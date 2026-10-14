import Alpine from 'alpinejs';

/*
 * Formulaires des pages Connexion et Inscription.
 * Les contrôles côté navigateur reprennent les règles du serveur (qui reste seul juge).
 */

const chiffres = (texte) => String(texte ?? '').replace(/\D/g, '');

// « 70123456 » ou « +226 70123456 » → « 70 12 34 56 » (8 chiffres maximum).
const formaterTelephone = (texte) => {
    let c = chiffres(texte);
    if (c.length > 8 && c.startsWith('226')) {
        c = c.slice(3);
    }
    return c.slice(0, 8).replace(/(\d{2})(?=\d)/g, '$1 ');
};

const telephoneValide = (texte) => /^[567]\d{7}$/.test(chiffres(texte));

// Bouton « Patientez… » : un seul envoi à la fois.
const envoi = {
    envoi: false,
    marquerEnvoi() {
        this.envoi = true;
    },
};

Alpine.data('saisieTelephone', ({ valeur = '', erreur = '', message = '' } = {}) => ({
    ...envoi,
    valeur: formaterTelephone(valeur),
    erreur,
    saisir(event) {
        this.valeur = formaterTelephone(event.target.value);
        this.erreur = '';
    },
    valider(event) {
        if (!telephoneValide(this.valeur)) {
            event.preventDefault();
            this.erreur = message;
            this.$refs.telephone.focus();
            return;
        }
        this.marquerEnvoi();
    },
}));

Alpine.data('codeOtp', ({ secondes = 0, erreur = '', message = '' } = {}) => ({
    ...envoi,
    valeurs: ['', '', '', '', '', ''],
    restant: secondes,
    erreur,
    init() {
        this.minuteur = setInterval(() => {
            if (this.restant > 0) {
                this.restant--;
            }
        }, 1000);
        this.$nextTick(() => this.cases()[0]?.focus());
    },
    destroy() {
        clearInterval(this.minuteur);
    },
    get code() {
        return this.valeurs.join('');
    },
    get minuteurTexte() {
        return `(${Math.floor(this.restant / 60)}:${String(this.restant % 60).padStart(2, '0')})`;
    },
    cases() {
        return [...this.$root.querySelectorAll('.otp')];
    },
    afficher() {
        this.cases().forEach((champ, i) => {
            champ.value = this.valeurs[i];
        });
    },
    saisir(i, event) {
        const saisie = chiffres(event.target.value);
        this.erreur = '';
        if (saisie.length > 1) {
            this.remplir(saisie, i);
            return;
        }
        this.valeurs[i] = saisie;
        event.target.value = saisie;
        if (saisie && i < 5) {
            this.cases()[i + 1].focus();
        }
        this.envoyerSiComplet();
    },
    touche(i, event) {
        const cases = this.cases();
        if (event.key === 'Backspace' && !this.valeurs[i] && i > 0) {
            event.preventDefault();
            this.valeurs[i - 1] = '';
            this.afficher();
            cases[i - 1].focus();
        } else if (event.key === 'ArrowLeft' && i > 0) {
            cases[i - 1].focus();
        } else if (event.key === 'ArrowRight' && i < 5) {
            cases[i + 1].focus();
        }
    },
    coller(event) {
        event.preventDefault();
        this.remplir((event.clipboardData || window.clipboardData).getData('text'), 0);
    },
    remplir(texte, depart) {
        const code = chiffres(texte).slice(0, 6);
        // Un code complet collé remplit toujours les 6 cases depuis la première.
        const debut = code.length === 6 ? 0 : depart;
        [...code].forEach((chiffre, k) => {
            if (debut + k < 6) {
                this.valeurs[debut + k] = chiffre;
            }
        });
        this.afficher();
        this.cases()[Math.min(debut + code.length, 5)].focus();
        this.envoyerSiComplet();
    },
    envoyerSiComplet() {
        if (this.code.length === 6 && !this.envoi) {
            this.$nextTick(() => this.$refs.formulaire.requestSubmit());
        }
    },
    valider(event) {
        if (!/^\d{6}$/.test(this.code)) {
            event.preventDefault();
            this.erreur = message;
            this.cases()[Math.max(0, this.code.length)]?.focus();
            return;
        }
        this.marquerEnvoi();
    },
}));

Alpine.data('formulaireIdentite', ({ valeurs = {}, messages = {}, ageMinimum = 18 } = {}) => ({
    ...envoi,
    nom: valeurs.nom ?? '',
    pays: valeurs.pays_residence || 'BF',
    erreurs: {},
    get diaspora() {
        return this.pays !== '' && this.pays !== 'BF';
    },
    majuscules(event) {
        this.nom = event.target.value.toLocaleUpperCase('fr-FR');
    },
    age(date) {
        const naissance = new Date(`${date}T00:00:00`);
        if (Number.isNaN(naissance.getTime())) {
            return -1;
        }
        const aujourdhui = new Date();
        let age = aujourdhui.getFullYear() - naissance.getFullYear();
        const anniversairePasse = aujourdhui.getMonth() > naissance.getMonth()
            || (aujourdhui.getMonth() === naissance.getMonth() && aujourdhui.getDate() >= naissance.getDate());
        return anniversairePasse ? age : age - 1;
    },
    valider(event) {
        // « Retour » enregistre la saisie sans la contrôler.
        if (event.submitter?.name === 'vers') {
            this.marquerEnvoi();
            return;
        }
        const f = event.target.elements;
        const erreurs = {};
        if (!f.nom.value.trim()) erreurs.nom = messages.nom;
        if (!f.prenoms.value.trim()) erreurs.prenoms = messages.prenoms;
        if (!f.date_naissance.value) erreurs.date_naissance = messages.date_naissance;
        else if (this.age(f.date_naissance.value) < ageMinimum) erreurs.date_naissance = messages.majeur;
        if (!f.pays_residence.value) erreurs.pays_residence = messages.pays;
        if (f.pays_residence.value === 'BF' && !f.region.value) erreurs.region = messages.region;
        if (!f.conditions.checked) erreurs.conditions = messages.conditions;

        this.erreurs = erreurs;
        const premier = Object.keys(erreurs)[0];
        if (premier) {
            event.preventDefault();
            f[premier]?.focus();
            return;
        }
        this.marquerEnvoi();
    },
}));

// Back-office : barre latérale repliable, état mémorisé dans le navigateur.
Alpine.data('menuBackoffice', () => ({
    replie: false,
    init() {
        try {
            this.replie = localStorage.getItem('raaga.bo.replie') === '1';
        } catch {
            // Stockage indisponible : menu déplié.
        }
    },
    basculer() {
        this.replie = !this.replie;
        try {
            localStorage.setItem('raaga.bo.replie', this.replie ? '1' : '0');
        } catch {
            // Sans stockage, l'état n'est simplement pas mémorisé.
        }
    },
}));

// Souscription : montant et frais recalculés en direct à partir des paramètres de la tranche (fournis par le serveur).
const fcfa = (n) => String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' FCFA';

Alpine.data('souscription', (p) => ({
    nb: p.nb,
    coches: [],
    fichier: false,
    get valide() {
        return Number.isInteger(this.nb) && this.nb >= p.min && (p.reste === null || this.nb <= p.reste);
    },
    get montant() {
        return this.valide ? this.nb * p.prix : 0;
    },
    get frais() {
        if (p.fraisType === 'POURCENTAGE') return Math.floor((this.montant * p.fraisValeur + 5000) / 10000);
        if (p.fraisType === 'FIXE') return this.montant > 0 ? p.fraisValeur : 0;
        return 0;
    },
    get total() {
        return this.montant + this.frais;
    },
    get justificatifRequis() {
        return p.seuil !== null && !p.justificatifFourni && p.deja + this.montant > p.seuil;
    },
    get pret() {
        return p.kyc && this.valide && this.coches.length === 4 && (!this.justificatifRequis || this.fichier);
    },
    fcfa,
    lire(valeur) {
        const n = parseInt(String(valeur).replace(/\D/g, ''), 10);
        this.nb = Number.isNaN(n) ? 0 : n;
    },
    ajuster(delta) {
        const max = p.reste === null ? Infinity : p.reste;
        this.nb = Math.min(max, Math.max(p.min, (this.nb || 0) + delta));
    },
}));

// Vérification d'identité : aperçu de la photo avant envoi, format et taille contrôlés (le serveur revérifie).
Alpine.data('pieceKyc', ({ maxOctets, messages }) => ({
    apercu: null,
    erreur: '',
    choisir(evenement) {
        const fichier = evenement.target.files[0];
        if (this.apercu) URL.revokeObjectURL(this.apercu);
        this.apercu = null;
        this.erreur = '';
        if (!fichier) return;
        if (!['image/jpeg', 'image/png'].includes(fichier.type)) {
            this.erreur = messages.format;
        } else if (fichier.size > maxOctets) {
            this.erreur = messages.taille;
        }
        if (this.erreur) {
            evenement.target.value = '';
            return;
        }
        this.apercu = URL.createObjectURL(fichier);
    },
}));

/*
 * Tableau de données (<x-tableau>) : la recherche (300 ms), les filtres, le tri et la pagination rechargent seulement
 * le bloc des résultats, puis l'adresse est mise à jour (rechargement et partage du lien). En cas d'échec, la page
 * complète est chargée.
 */
Alpine.data('tableau', () => ({
    charge: false,
    controleur: null,
    init() {
        window.addEventListener('popstate', () => {
            if (document.contains(this.$el)) {
                window.location.reload();
            }
        });
    },
    soumettre() {
        const url = new URL(this.$refs.formulaire.action, window.location.origin);
        new FormData(this.$refs.formulaire).forEach((valeur, cle) => {
            if (String(valeur) !== '' && !(cle === 'lignes' && valeur === '25')) {
                url.searchParams.set(cle, valeur);
            }
        });
        this.charger(url.toString());
    },
    suivre(evenement) {
        const lien = evenement.target.closest('a[data-tableau-lien]');
        if (!lien || evenement.ctrlKey || evenement.metaKey || evenement.shiftKey) {
            return;
        }
        evenement.preventDefault();
        this.charger(lien.href);
    },
    async charger(url) {
        this.controleur?.abort();
        this.controleur = new AbortController();
        this.charge = true;
        try {
            const reponse = await fetch(url, {
                headers: { 'X-Tableau': this.$el.dataset.tableau, 'X-Requested-With': 'XMLHttpRequest' },
                signal: this.controleur.signal,
            });
            if (!reponse.ok) {
                throw new Error(String(reponse.status));
            }
            this.$refs.resultats.innerHTML = await reponse.text();
            window.history.pushState({}, '', url);
            // Le tri choisi par un en-tête est conservé pour les recherches suivantes.
            this.$refs.formulaire.elements.tri.value = new URL(url, window.location.origin).searchParams.get('tri') ?? '';
        } catch (erreur) {
            if (erreur.name !== 'AbortError') {
                window.location.href = url;
            }
        } finally {
            this.charge = false;
        }
    },
}));

window.Alpine = Alpine;

Alpine.start();
