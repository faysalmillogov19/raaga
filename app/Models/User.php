<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role as RoleEnum;
use App\Enums\StatutKyc;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'name', 'nom', 'prenoms', 'email', 'telephone', 'password', 'role_id',
    'date_naissance', 'nationalite', 'pays_residence', 'region',
    'statut_kyc', 'niveau_kyc', 'actif', 'institution_id',
])]
#[Hidden(['password', 'remember_token', 'totp_secret', 'codes_secours'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Le rôle est lu à chaque requête (middleware, navigation) : chargé d'office.
     *
     * @var list<string>
     */
    protected $with = ['role'];

    /**
     * Valeurs par défaut en mémoire (identiques à celles de la base).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'actif' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'menus_backoffice' => 'array',
            'password' => 'hashed',
            'date_naissance' => 'date',
            'statut_kyc' => StatutKyc::class,
            'niveau_kyc' => 'integer',
            'actif' => 'boolean',
            // Secrets chiffrés en base avec la clé de l'application.
            'totp_secret' => 'encrypted',
            'codes_secours' => 'encrypted:array',
            'totp_active_le' => 'datetime',
            'totp_dernier_pas' => 'integer',
            'echecs_connexion' => 'integer',
            'verrouille_jusqua' => 'datetime',
            'derniere_connexion_le' => 'datetime',
        ];
    }

    public function estBackoffice(): bool
    {
        return $this->role?->code?->estBackoffice() ?? false;
    }

    public function estInstitutionnel(): bool
    {
        return $this->role?->code?->estInstitutionnel() ?? false;
    }

    /** Signataire actif de son institution : signe, déclare les virements, gère les utilisateurs. */
    public function estSignataire(): bool
    {
        return $this->actif && $this->codeRole() === RoleEnum::InstitutionSignataire;
    }

    /**
     * Institution des comptes institutionnels (null pour tous les autres comptes).
     *
     * @return BelongsTo<Institution, $this>
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function totpActive(): bool
    {
        return $this->totp_secret !== null && $this->totp_active_le !== null;
    }

    public function estVerrouille(): bool
    {
        return $this->verrouille_jusqua !== null && $this->verrouille_jusqua->isFuture();
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Code du rôle (valeur de l'enum), pratique pour les comparaisons et les redirections.
     */
    public function codeRole(): RoleEnum
    {
        return $this->role->code;
    }

    /**
     * Indique si l'utilisateur possède l'un des rôles donnés.
     */
    public function hasRole(RoleEnum ...$roles): bool
    {
        return in_array($this->role?->code, $roles, true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(RoleEnum::SuperAdmin);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(RoleEnum::SuperAdmin, RoleEnum::Admin);
    }

    public function isPorteurProjet(): bool
    {
        return $this->hasRole(RoleEnum::PorteurProjet);
    }

    public function isInvestisseur(): bool
    {
        return $this->hasRole(RoleEnum::Investisseur);
    }

    public function isVisiteur(): bool
    {
        return $this->hasRole(RoleEnum::Visiteur);
    }

    public function isCitoyen(): bool
    {
        return $this->hasRole(RoleEnum::Citoyen);
    }

    public function kycValide(): bool
    {
        return $this->statut_kyc === StatutKyc::Valide;
    }

    /**
     * @return HasMany<KycDocument, $this>
     */
    public function kycDocuments(): HasMany
    {
        return $this->hasMany(KycDocument::class);
    }

    /**
     * @return HasMany<Souscription, $this>
     */
    public function souscriptions(): HasMany
    {
        return $this->hasMany(Souscription::class);
    }

    /**
     * @return HasMany<LigneRegistre, $this>
     */
    public function lignesRegistre(): HasMany
    {
        return $this->hasMany(LigneRegistre::class);
    }
}
