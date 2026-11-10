<?php

namespace App\Http\Controllers\Compte;

use App\Enums\TypeCarte;
use App\Enums\TypeDocumentKyc;
use App\Enums\TypeTitulaire;
use App\Http\Controllers\Controller;
use App\Models\Boutique;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Boutiques\ReservationsBoutiques;
use App\Services\Cartes\Cartes;
use App\Services\Kyc\JustificatifsGroupeSanguin;
use App\Services\Kyc\RegistreCommerce;
use App\Services\Kyc\VerificationIdentite;
use App\Support\Ifu;
use App\Support\Nip;
use App\Support\Rccm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** /espace/verification : dépôt des pièces d'identité du citoyen connecté et suivi du statut. */
class VerificationController extends Controller
{
    public function __construct(private readonly VerificationIdentite $verification) {}

    public function show(Request $request): View
    {
        $user = $request->user();

        return view('public.espace.verification', [
            'user' => $user,
            'dossier' => $this->verification->dossier($user),
            'typePiece' => $this->verification->typePiece($user),
            'aFournir' => $this->verification->aFournir($user),
            'justificatifGroupe' => JustificatifsGroupeSanguin::dernier($user),
            'justificatifRccm' => RegistreCommerce::justificatif($user),
            // Venu d'une boutique (10/11/2026) : code de la boutique, pour y revenir une fois la demande faite.
            'retour' => self::retour($request),
        ]);
    }

    /** Code de la boutique d'où vient le citoyen (paramètre « retour »), s'il existe ; sinon null. */
    private static function retour(Request $request): ?string
    {
        $code = (string) $request->input('retour', '');

        return $code !== '' && strlen($code) <= 20 && Boutique::query()->where('code', $code)->exists() ? $code : null;
    }

    public function store(Request $request): RedirectResponse
    {
        $fichier = ['nullable', 'file', 'mimes:jpg,jpeg,png', 'mimetypes:image/jpeg,image/png', 'max:5120'];
        // Attestation IFU, justificatif de pouvoir : souvent des PDF.
        $document = ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:5120'];
        $e = __('public.verification.erreurs');
        $user = $request->user();
        // Type de la pièce : choisi ici, sinon celui déjà déposé ; CNIB ou CIB-AES : le numéro est le NIP (06/11/2026).
        $typePiece = TypeDocumentKyc::tryFrom((string) $request->input('type_piece')) ?? $this->verification->typePiece($user);
        // Formalisation (10/11/2026) : personne physique au premier dépôt ; « oui » (ou IFU, RCCM déjà connus) : IFU et RCCM exigés.
        $question = VerificationIdentite::questionFormalisation($user);
        $formalisee = VerificationIdentite::formalisationDemandee($user) && (! $question || $request->input('formalisee') === 'oui');
        $f = __('public.verification.formalisation.erreurs');

        $donnees = $request->validate([
            // CNIB, CIB-AES ou passeport, et rien d'autre.
            'type_piece' => ['nullable', Rule::in(array_map(fn (TypeDocumentKyc $t) => $t->value, TypeDocumentKyc::piecesIdentite()))],
            'numero_piece' => ['nullable', 'string', 'min:5', 'max:40', Nip::regle($typePiece)],
            // Date d'expiration de la pièce : obligatoire ; la carte Raaga ne la dépasse jamais.
            'piece_expire_le' => ['nullable', 'date_format:Y-m-d', 'after:today'],
            'recto' => $fichier, 'verso' => $fichier, 'selfie' => $fichier,
            'ifu' => $document, 'pouvoir' => $document, 'rccm' => $document,
            ...VerificationIdentite::reglesInfos($user),
            'formalisee' => [Rule::requiredIf($question), 'nullable', Rule::in(['oui', 'non'])],
            ...(VerificationIdentite::formalisationDemandee($user) && ! $formalisee ? ['numero_ifu' => ['nullable']] : []),
            ...($formalisee ? [
                'numero_ifu' => Ifu::regles($user, 'required'),
                'ifu' => ['required', ...array_slice($document, 1)],
                ...Rccm::regles('required', $user),
                'justificatif_rccm' => ['required', ...array_slice($document, 1)],
            ] : []),
        ], [...$e['fichiers'], ...($formalisee ? Rccm::messages() : []), 'formalisee.*' => $f['question'], 'numero_ifu.required' => $f['ifu'],
            'ifu.required' => $f['attestation_ifu'], 'justificatif_rccm.*' => __('public.rccm.erreurs.document'), 'type_piece.*' => $e['type'], 'piece_expire_le.*' => $e['expiration'], ...array_fill_keys(['numero_piece.required', 'numero_piece.string', 'numero_piece.min', 'numero_piece.max'], $e['numero_piece']),
            'sexe.*' => $e['sexe'], 'lieu_naissance.*' => $e['lieu_naissance']], __('public.verification.attributs'));

        DB::transaction(function () use ($request, $user, $donnees, $formalisee) {
            $fichiers = array_filter($request->only(['recto', 'verso', 'selfie', 'ifu', 'pouvoir', 'rccm']));
            // Activité formalisée : IFU et RCCM enregistrés, leurs justificatifs déposés avec les pièces d'identité.
            if ($formalisee) {
                Ifu::enregistrer($user, (string) $donnees['numero_ifu'], $user, 'verification_identite');
                $user->forceFill(['rccm' => Rccm::normaliser($donnees['rccm']), 'rccm_immatricule_le' => $donnees['rccm_immatricule_le'],
                    'rccm_greffe_id' => (int) $donnees['rccm_greffe_id'], 'rccm_greffe_autre' => Rccm::precisionAutre($donnees)])->save();
                $fichiers['rccm'] = $request->file('justificatif_rccm');
            } elseif (VerificationIdentite::formalisationDemandee($user)) {
                unset($fichiers['ifu'], $fichiers['rccm']);
            }
            $this->verification->deposer(
                $user,
                isset($donnees['type_piece']) ? TypeDocumentKyc::from($donnees['type_piece']) : null,
                $fichiers,
                array_intersect_key($donnees, array_flip(['numero_piece', 'piece_expire_le', 'sexe', 'lieu_naissance', 'numero_ifu'])),
            );
        });

        // Venu d'une boutique : retour à sa page dès que la demande est faite (dossier complet).
        $retour = self::retour($request);
        if ($retour !== null && $this->verification->demandeFaite($request->user()->fresh())) {
            return redirect()->route('boutiques.boutique', $retour)->avecSucces('public.boutiques.identite_avant.envoyee');
        }

        return redirect()->route('espace.verification', array_filter(['retour' => $retour]))->avecSucces('public.verification.envoye');
    }

    /**
     * Groupe sanguin (05/11/2026) : groupe déclaré avec l'accord daté et son justificatif, modifiables à tout moment ;
     * l'ADMIN valide le justificatif. Groupe retiré : l'accord aussi, rien n'est imprimé.
     */
    public function groupeSanguin(Request $request, JustificatifsGroupeSanguin $justificatifs): RedirectResponse
    {
        $user = $request->user();
        $e = __('public.verification.groupe_sanguin.erreurs');
        $donnees = $request->validate([
            'groupe_sanguin' => ['nullable', Rule::in(User::GROUPES_SANGUINS)],
            'consentement_groupe_sanguin' => [filled($request->input('groupe_sanguin')) ? 'accepted' : 'nullable'],
            'justificatif' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:5120'],
        ], ['groupe_sanguin.*' => $e['groupe'], 'consentement_groupe_sanguin.*' => $e['consentement'], 'justificatif.*' => $e['justificatif']]);
        $groupe = $donnees['groupe_sanguin'] ?? null;

        $justificatifs->enregistrerGroupe($user, $groupe);
        if ($groupe && $request->hasFile('justificatif')) {
            $justificatifs->deposer($user, $request->file('justificatif'));
        } elseif (Cartes::courante($user, TypeCarte::Commercant)) {
            app(Cartes::class)->synchroniser($user, TypeCarte::Commercant, $user);
        }

        return redirect()->to(route('espace.verification').'#groupe-sanguin')->avecSucces('public.verification.groupe_sanguin.enregistre');
    }

    /**
     * IFU d'une personne physique pas encore formalisée (10/11/2026) : numéro et attestation, vérifiés par l'ADMIN ; la
     * carte de commerçant est émise une fois l'IFU validé.
     */
    public function ifu(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless(VerificationIdentite::ifuADeposer($user), 404);
        $donnees = $request->validate([
            'numero_ifu' => Ifu::regles($user, 'required'),
            'attestation_ifu' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:5120'],
        ], ['attestation_ifu.*' => __('public.verification.formalisation.erreurs.attestation_ifu')]);
        $chemins = [];
        try {
            DB::transaction(fn () => ReservationsBoutiques::deposerAttestationIfu($user, Ifu::normaliser($donnees['numero_ifu']), $request->file('attestation_ifu'), $chemins));
        } catch (\Throwable $e) {
            Storage::disk(VerificationIdentite::DISQUE)->delete($chemins);

            throw $e;
        }

        return redirect()->to(route('espace.verification').'#ifu')->avecSucces('public.verification.ifu.enregistre');
    }

    /** RCCM (05/11/2026) : numéro, date, greffe et justificatif ; obligatoire pour une personne morale, facultatif sinon. */
    public function rccm(Request $request, RegistreCommerce $registre): RedirectResponse
    {
        $user = $request->user();
        $donnees = $request->validate([
            ...Rccm::regles($user->estPersonneMorale() ? 'required' : 'nullable', $user),
            'document' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:5120'],
        ], [...Rccm::messages(), 'document.*' => __('public.rccm.erreurs.document')]);
        $registre->enregistrer($user, $donnees, $request->file('document'));

        return redirect()->to(route('espace.verification').'#rccm')->avecSucces('public.verification.rccm.enregistre');
    }

    /** Type de titulaire (physique ou moral) : modifiable seulement en repassant par la vérification d'identité. */
    public function titulaire(Request $request): RedirectResponse
    {
        $e = __('public.titulaires.erreurs');
        $donnees = $request->validate([
            'type_titulaire' => ['required', Rule::enum(TypeTitulaire::class)],
            'raison_sociale' => ['nullable', 'required_if:type_titulaire,MORALE', 'string', 'min:2', 'max:200'],
            'forme_morale' => ['nullable', 'required_if:type_titulaire,MORALE', Rule::in(TypeTitulaire::FORMES)],
        ], ['type_titulaire.*' => $e['type'], 'raison_sociale.*' => $e['raison_sociale'], 'forme_morale.*' => $e['forme']]);
        $this->verification->changerTitulaire($request->user(), TypeTitulaire::from($donnees['type_titulaire']), $donnees['raison_sociale'] ?? null, $donnees['forme_morale'] ?? null);

        return redirect()->route('espace.verification')->avecSucces('public.titulaires.change');
    }

    /** Fichier d'une pièce : uniquement pour son titulaire (le VALIDATEUR passe par le back-office). */
    public function fichier(Request $request, KycDocument $document): StreamedResponse
    {
        abort_unless($document->user_id === $request->user()->id, 403);

        return self::servir($document);
    }

    /** Réponse commune aux routes contrôlées : jamais mise en cache, jamais interprétée par le navigateur. */
    public static function servir(KycDocument $document): StreamedResponse
    {
        abort_unless(Storage::disk(VerificationIdentite::DISQUE)->exists($document->chemin_fichier), 404);

        return Storage::disk(VerificationIdentite::DISQUE)->response($document->chemin_fichier, null, [
            'Content-Type' => $document->mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; sandbox",
        ]);
    }
}
