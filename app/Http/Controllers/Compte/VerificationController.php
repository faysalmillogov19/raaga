<?php

namespace App\Http\Controllers\Compte;

use App\Enums\TypeCarte;
use App\Enums\TypeDocumentKyc;
use App\Enums\TypeTitulaire;
use App\Http\Controllers\Controller;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Cartes\Cartes;
use App\Services\Kyc\JustificatifsGroupeSanguin;
use App\Services\Kyc\RegistreCommerce;
use App\Services\Kyc\VerificationIdentite;
use App\Support\Nip;
use App\Support\Rccm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $fichier = ['nullable', 'file', 'mimes:jpg,jpeg,png', 'mimetypes:image/jpeg,image/png', 'max:5120'];
        // Attestation IFU, justificatif de pouvoir : souvent des PDF.
        $document = ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:5120'];
        $e = __('public.verification.erreurs');
        // Type de la pièce : choisi ici, sinon celui déjà déposé ; CNIB ou CIB-AES : le numéro est le NIP (06/11/2026).
        $typePiece = TypeDocumentKyc::tryFrom((string) $request->input('type_piece')) ?? $this->verification->typePiece($request->user());

        $donnees = $request->validate([
            // CNIB, CIB-AES ou passeport, et rien d'autre.
            'type_piece' => ['nullable', Rule::in(array_map(fn (TypeDocumentKyc $t) => $t->value, TypeDocumentKyc::piecesIdentite()))],
            'numero_piece' => ['nullable', 'string', 'min:5', 'max:40', Nip::regle($typePiece)],
            // Date d'expiration de la pièce : obligatoire ; la carte Raaga ne la dépasse jamais.
            'piece_expire_le' => ['nullable', 'date_format:Y-m-d', 'after:today'],
            'recto' => $fichier, 'verso' => $fichier, 'selfie' => $fichier,
            'ifu' => $document, 'pouvoir' => $document, 'rccm' => $document,
            ...VerificationIdentite::reglesInfos($request->user()),
        ], [...$e['fichiers'], 'type_piece.*' => $e['type'], 'piece_expire_le.*' => $e['expiration'], ...array_fill_keys(['numero_piece.required', 'numero_piece.string', 'numero_piece.min', 'numero_piece.max'], $e['numero_piece']),
            'sexe.*' => $e['sexe'], 'lieu_naissance.*' => $e['lieu_naissance']], __('public.verification.attributs'));

        $this->verification->deposer(
            $request->user(),
            isset($donnees['type_piece']) ? TypeDocumentKyc::from($donnees['type_piece']) : null,
            array_filter($request->only(['recto', 'verso', 'selfie', 'ifu', 'pouvoir', 'rccm'])),
            array_intersect_key($donnees, array_flip(['numero_piece', 'piece_expire_le', 'sexe', 'lieu_naissance', 'numero_ifu'])),
        );

        return redirect()->route('espace.verification')->avecSucces('public.verification.envoye');
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
