/*
 * Raaga : JavaScript natif, sans étape de compilation. Chargé avant Alpine.js (public/vendor/alpinejs/3.17.4), tous
 * deux avec « defer » : les composants Alpine sont déclarés à l'événement alpine:init, qu'Alpine émet au démarrage.
 */
(() => {
'use strict';

// Déclare un composant Alpine (x-data="nom(...)") au démarrage d'Alpine.
const composant = (nom, fabrique) => document.addEventListener('alpine:init', () => window.Alpine.data(nom, fabrique));

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

composant('saisieTelephone', ({ valeur = '', erreur = '', message = '' } = {}) => ({
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

composant('codeOtp', ({ secondes = 0, erreur = '', message = '' } = {}) => ({
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

composant('formulaireIdentite', ({ valeurs = {}, messages = {}, ageMinimum = 18 } = {}) => ({
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
composant('menuBackoffice', () => ({
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

composant('souscription', (p) => ({
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
        return this.valide && this.coches.length === 4 && (!this.justificatifRequis || this.fichier);
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

/*
 * Compte à rebours avant l'ouverture d'une campagne (« dans 2 j 3 h 15 min »), mis à jour chaque minute ; à
 * l'échéance, invite à recharger la page. Sans JavaScript, la date d'ouverture reste écrite en toutes lettres.
 */
composant('compteARebours', (cible, m = {}, parSeconde = false) => ({
    // texte : « dans 3 j 4 h 12 min » (page des boutiques) ; j, h, mn, s : cases du thème « nuit » (x-public.compte-a-rebours).
    texte: '',
    j: 0,
    h: 0,
    mn: 0,
    s: 0,
    init() {
        this.maj();
        this.minuteur = setInterval(() => this.maj(), parSeconde ? 1000 : 30000);
    },
    destroy() {
        clearInterval(this.minuteur);
    },
    deux(n) {
        return String(n).padStart(2, '0');
    },
    maj() {
        const ecart = new Date(cible).getTime() - Date.now();
        const secondes = Math.max(0, Math.floor(ecart / 1000));
        this.j = Math.floor(secondes / 86400);
        this.h = Math.floor((secondes % 86400) / 3600);
        this.mn = Math.floor((secondes % 3600) / 60);
        this.s = secondes % 60;
        const reste = Math.floor(ecart / 60000);
        if (reste <= 0) {
            this.texte = m.imminent ?? '';
            if (secondes === 0) {
                clearInterval(this.minuteur);
            }
            return;
        }
        const j = Math.floor(reste / 1440);
        const h = Math.floor((reste % 1440) / 60);
        const min = reste % 60;
        this.texte = m.dans ? [m.dans, j ? `${j} ${m.jours}` : '', (j || h) ? `${h} ${m.heures}` : '', `${min} ${m.minutes}`].filter(Boolean).join(' ') : '';
    },
}));

/*
 * Aperçu d'un texte mis en forme (Markdown léger) : rendu demandé au serveur, qui neutralise tout HTML saisi ; le
 * résultat (déjà nettoyé) remplace l'aperçu. En cas d'échec, l'aperçu précédent reste affiché.
 */
composant('apercuTexte', (url) => ({
    async maj(texte) {
        try {
            const reponse = await envoyer(url, { texte });
            if (reponse.ok) {
                this.$refs.rendu.innerHTML = (await reponse.json()).html;
            }
        } catch {
            // Aperçu inchangé.
        }
    },
}));

// Vérification d'identité : aperçu de la photo avant envoi, format et taille contrôlés (le serveur revérifie).
composant('pieceKyc', ({ maxOctets, messages }) => ({
    apercu: null,
    erreur: '',
    choisir(evenement) {
        const fichier = evenement.target.files[0];
        if (this.apercu) URL.revokeObjectURL(this.apercu);
        this.apercu = null;
        this.erreur = '';
        if (!fichier) return;
        // Types acceptés : ceux du champ (le PDF est permis pour le RCCM, l'attestation IFU et le justificatif de pouvoir).
        const acceptes = (evenement.target.getAttribute('accept') || 'image/jpeg,image/png').split(',');
        if (!acceptes.includes(fichier.type)) {
            this.erreur = messages.format;
        } else if (fichier.size > maxOctets) {
            this.erreur = messages.taille;
        }
        if (this.erreur) {
            evenement.target.value = '';
            return;
        }
        // Aperçu des seules images (pas des PDF).
        if (fichier.type.startsWith('image/')) {
            this.apercu = URL.createObjectURL(fichier);
        }
    },
}));

/*
 * Tableau de données (<x-tableau>) : la recherche (300 ms), les filtres, le tri et la pagination rechargent seulement
 * le bloc des résultats, puis l'adresse est mise à jour (rechargement et partage du lien). En cas d'échec, la page
 * complète est chargée.
 */
/*
 * Soumission groupée à validation depuis une liste (<x-tableau> avec sélection) : cases des lignes à soumettre ou à
 * retirer, barre collante, contrôle préalable de chaque élément (✓ prêt ou ✗ raison) avant l'envoi des seuls éléments prêts.
 */
composant('soumissionGroupee', ({ controle }) => ({
    aSoumettre: 0,
    aRetirer: 0,
    controles: [],
    enCours: false,
    erreur: false,
    cases(etat = null) {
        return [...this.$el.querySelectorAll(`input[name="elements[]"][data-etat${etat ? `="${etat}"` : ''}]`)];
    },
    compter() {
        this.aSoumettre = this.cases('soumettre').filter((c) => c.checked).length;
        this.aRetirer = this.cases('retirer').filter((c) => c.checked).length;
    },
    toutCocher(coche) {
        this.cases('soumettre').forEach((c) => { c.checked = coche; });
        this.compter();
    },
    get total() { return this.aSoumettre + this.aRetirer; },
    get prets() { return this.controles.filter((c) => c.pret); },
    selectionnes(etat) { return this.cases(etat).filter((c) => c.checked).map((c) => c.value); },
    // Contrôle préalable ; la fenêtre ne s'ouvre que si un élément a un problème (✗), sinon soumission directe.
    async ouvrirSoumission() {
        this.controles = [];
        this.erreur = false;
        this.enCours = true;
        try {
            const reponse = await fetch(controle, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': jeton(), 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ elements: this.selectionnes('soumettre') }),
            });
            if (!reponse.ok) {
                throw new Error(String(reponse.status));
            }
            this.controles = (await reponse.json()).elements;
            if (this.controles.length > 0 && this.controles.every((c) => c.pret)) {
                await this.$nextTick();
                this.$refs.formulaireSoumettre.submit();
                return;
            }
        } catch {
            this.erreur = true;
        } finally {
            this.enCours = false;
        }
        this.$refs.dialogueSoumettre.showModal();
    },
}));

composant('tableau', () => ({
    charge: false,
    controleur: null,
    init() {
        window.addEventListener('popstate', () => {
            if (document.contains(this.$el)) {
                window.location.reload();
            }
        });
    },
    // Racine du composant (data-tableau) : this.$el désigne l'élément d'où part l'événement (lien, liste, formulaire).
    get racine() {
        return this.$root;
    },
    soumettre() {
        const url = new URL(this.$refs.formulaire.action, window.location.origin);
        const lignesDefaut = this.racine.dataset.lignesDefaut ?? '25';
        new FormData(this.$refs.formulaire).forEach((valeur, cle) => {
            if (String(valeur) !== '' && !(cle === 'lignes' && valeur === lignesDefaut)) {
                url.searchParams.set(cle, valeur);
            }
        });
        this.charger(url.toString());
    },
    // « Réinitialiser » : filtres, recherche et tri effacés, première page.
    reinitialiser(evenement) {
        const formulaire = this.$refs.formulaire;
        formulaire.querySelectorAll('select:not([name="lignes"])').forEach((liste) => { liste.value = ''; });
        formulaire.querySelectorAll('input[name="q"], input[name="tri"]').forEach((champ) => { champ.value = ''; });
        formulaire.querySelectorAll('input[type="hidden"]:not([name="tri"])').forEach((champ) => champ.remove());
        this.charger(evenement.currentTarget.href);
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
        const id = this.racine.dataset.tableau;
        try {
            const reponse = await fetch(url, {
                headers: { 'X-Tableau': id, 'X-Requested-With': 'XMLHttpRequest' },
                signal: this.controleur.signal,
            });
            const html = reponse.ok ? await reponse.text() : '';
            // Seul le fragment de CE tableau est inséré (confirmé par l'en-tête X-Tableau-Fragment). Page complète, page de
            // connexion après expiration de session, redirection ou erreur : la page est rechargée normalement à cette adresse.
            if (!reponse.ok || reponse.redirected || reponse.headers.get('X-Tableau-Fragment') !== id
                || !html.trimStart().startsWith('<div data-tableau-resultats')) {
                window.location.href = url;

                return;
            }
            this.$refs.resultats.innerHTML = html;
            window.history.pushState({}, '', url);
            this.$dispatch('tableau-charge');
            // Bouton « Exporter » de l'en-tête de page : il suit le résultat affiché.
            const exporter = this.$refs.resultats.querySelector('[data-tableau-resultats]')?.dataset.export;
            if (exporter) {
                document.querySelectorAll(`[data-export-tableau="${id}"]`).forEach((lien) => { lien.href = exporter; });
            }
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

/*
 * Cadre du back-office : barre latérale ouverte ou fermée (choix mémorisé sur cet appareil ; fermée par défaut sur
 * mobile), thème clair ou Forêt (mémorisé pour l'utilisateur, côté serveur), notifications marquées comme lues.
 */
const jeton = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const envoyer = (url, donnees = {}) => fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': jeton(), 'X-Requested-With': 'XMLHttpRequest' },
    body: JSON.stringify(donnees),
});

/*
 * Menu dépliant de la barre latérale (<x-admin.menu-deroulant>) : déplié ou replié (état mémorisé pour l'utilisateur) ;
 * barre réduite en icônes : panneau flottant au survol ou au clic, placé à la hauteur du bouton.
 */
composant('menuDeroulant', ({ deplie = false, cle = '', url = '' } = {}) => ({
    deplie,
    flottant: false,
    haut: 0,
    minuterie: null,
    replie() {
        return this.$root.closest('.adm-ferme') !== null && window.matchMedia('(min-width: 1024px)').matches;
    },
    positionner() {
        this.haut = Math.round(this.$refs.bouton.getBoundingClientRect().top);
    },
    basculer() {
        if (this.replie()) {
            this.positionner();
            this.flottant = !this.flottant;
            return;
        }
        this.deplie = !this.deplie;
        envoyer(url, { menu: cle, ouvert: this.deplie }).catch(() => {
            // Sans réseau, l'état n'est simplement pas mémorisé.
        });
    },
    survol() {
        if (this.replie()) {
            this.garder();
            this.positionner();
            this.flottant = true;
        }
    },
    garder() {
        clearTimeout(this.minuterie);
    },
    fermerBientot() {
        clearTimeout(this.minuterie);
        this.minuterie = setTimeout(() => {
            this.flottant = false;
        }, 200);
    },
}));

composant('cadreAdmin', ({ theme = 'clair', urlTheme = '', urlLues = '' } = {}) => ({
    theme,
    ouvert: true,
    init() {
        const mobile = window.matchMedia('(max-width: 1023px)').matches;
        let memorise = null;
        try {
            memorise = localStorage.getItem('raaga.admin.menu');
        } catch {
            // Stockage indisponible : valeur par défaut.
        }
        this.ouvert = mobile ? false : memorise !== 'ferme';
    },
    basculerMenu() {
        this.ouvert = !this.ouvert;
        if (!window.matchMedia('(max-width: 1023px)').matches) {
            try {
                localStorage.setItem('raaga.admin.menu', this.ouvert ? 'ouvert' : 'ferme');
            } catch {
                // Sans stockage, le choix n'est pas mémorisé.
            }
        }
    },
    async basculerTheme() {
        const precedent = this.theme;
        this.theme = this.theme === 'foret' ? 'clair' : 'foret';
        try {
            const reponse = await envoyer(urlTheme, { theme: this.theme });
            if (!reponse.ok) {
                throw new Error(String(reponse.status));
            }
        } catch {
            this.theme = precedent;
        }
    },
    marquerLues() {
        envoyer(urlLues).catch(() => {});
    },
}));

// Recherche globale : les dernières recherches sont proposées au focus (conservées sur cet appareil).
composant('rechercheGlobale', () => ({
    ouverte: false,
    recentes: [],
    init() {
        try {
            this.recentes = JSON.parse(localStorage.getItem('raaga.admin.recherches') ?? '[]').slice(0, 5);
        } catch {
            this.recentes = [];
        }
    },
    memoriser() {
        const terme = this.$refs.champ.value.trim();
        if (!terme) {
            return;
        }
        this.recentes = [terme, ...this.recentes.filter((t) => t !== terme)].slice(0, 5);
        try {
            localStorage.setItem('raaga.admin.recherches', JSON.stringify(this.recentes));
        } catch {
            // Sans stockage, rien n'est conservé.
        }
    },
}));

/*
 * Fenêtres modales du back-office (<x-admin.fenetre>) : <dialog> natif ouvert par showModal(). La page derrière ne
 * défile plus ; focus sur le premier champ, rendu au bouton d'origine ; fermeture par ×, Annuler, Échap ou clic sur
 * le fond. Envoi sans rechargement : erreurs de validation (422) sous chaque champ, fenêtre laissée ouverte ; en cas
 * de succès, le contenu de la page est remplacé par celui renvoyé (ligne du tableau, message en haut de la liste).
 * Les liens [data-fenetre-url] ouvrent une fenêtre chargée depuis le serveur (formulaire de modification d'une ligne).
 */
const CHAMPS = 'input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])';

const verrouillerPage = (verrou) => document.documentElement.classList.toggle('adm-fenetre-ouverte', verrou);

// « lignes.RC.3.loyer » → « lignes[RC][3][loyer] » (nom du champ dans le formulaire).
const nomChamp = (cle) => cle.split('.').map((morceau, i) => (i === 0 ? morceau : `[${morceau}]`)).join('');

// Contenu de la page remplacé par la page renvoyée par le serveur (après redirection) ; focus rendu si possible.
const remplacerContenu = (html, url, focusId) => {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const nouveau = doc.querySelector('#contenu');
    const actuel = document.querySelector('#contenu');
    if (!nouveau || !actuel) {
        window.location.href = url;
        return;
    }
    actuel.innerHTML = nouveau.innerHTML;
    document.title = doc.title;
    if (url && url !== window.location.href) {
        window.history.pushState({}, '', url);
    }
    window.setTimeout(() => {
        const cible = (focusId && document.getElementById(focusId)) || actuel.querySelector('[role="status"], [role="alert"]') || actuel.querySelector('h1');
        cible?.setAttribute('tabindex', cible.getAttribute('tabindex') ?? '-1');
        cible?.focus({ preventScroll: true });
        actuel.scrollIntoView({ block: 'start' });
    }, 0);
};

composant('fenetre', () => ({
    envoi: false,
    erreur: '',
    origine: null,
    init() {
        if (this.$el.hasAttribute('data-ouvrir')) {
            this.$nextTick(() => this.ouvrir());
        }
    },
    ouvrir(evenement = null) {
        this.origine = evenement?.currentTarget ?? window.raagaFenetreOrigine ?? document.activeElement;
        window.raagaFenetreOrigine = null;
        const dialogue = this.$refs.dialogue;
        if (!dialogue.open) {
            dialogue.showModal();
        }
        verrouillerPage(true);
        const premier = dialogue.querySelector('.adm-fenetre-corps ' + CHAMPS) ?? dialogue.querySelector('.adm-fenetre-pied button[type="submit"]');
        premier?.focus();
    },
    fermer() {
        this.$refs.dialogue.close();
    },
    fermee() {
        verrouillerPage(false);
        this.erreur = '';
        this.origine?.isConnected && this.origine.focus();
        this.$dispatch('fenetre-fermee');
    },
    // Clic sur le fond assombri (en dehors du cadre de la fenêtre).
    clicFond(evenement) {
        if (evenement.target === this.$refs.dialogue) {
            this.fermer();
        }
    },
    effacerErreurs(formulaire) {
        this.erreur = '';
        formulaire.querySelectorAll('[data-erreur-js]').forEach((p) => p.remove());
        formulaire.querySelectorAll('[aria-invalid="true"]').forEach((champ) => {
            champ.removeAttribute('aria-invalid');
            champ.classList.remove('est-invalide');
        });
    },
    afficherErreurs(formulaire, erreurs) {
        let premier = null;
        Object.entries(erreurs).forEach(([cle, messages]) => {
            const champ = formulaire.querySelector(`[name="${nomChamp(cle)}"], [name="${nomChamp(cle)}[]"]`);
            const message = document.createElement('p');
            message.className = 'message-erreur';
            message.setAttribute('role', 'alert');
            message.dataset.erreurJs = '';
            message.textContent = messages[0];
            if (!champ) {
                this.erreur = [this.erreur, messages[0]].filter(Boolean).join(' ');
                return;
            }
            champ.setAttribute('aria-invalid', 'true');
            champ.classList.add('est-invalide');
            const conteneur = champ.closest('.formulaire-champ, fieldset') ?? champ.parentElement;
            message.id = `${champ.id || cle}-erreur-js`;
            champ.setAttribute('aria-describedby', [champ.getAttribute('aria-describedby'), message.id].filter(Boolean).join(' '));
            conteneur.appendChild(message);
            premier ??= champ;
        });
        premier?.focus();
    },
    async envoyer(evenement) {
        const formulaire = evenement.target;
        const bouton = evenement.submitter;
        if (bouton?.getAttribute('formmethod')?.toLowerCase() === 'get') {
            const adresse = new URL(bouton.formAction, window.location.origin);
            new FormData(formulaire).forEach((valeur, cle) => {
                if (typeof valeur === 'string' && valeur !== '' && !cle.startsWith('_')) {
                    adresse.searchParams.set(cle, valeur);
                }
            });
            window.location.href = adresse.toString();
            return;
        }
        if (this.envoi) {
            return;
        }
        this.effacerErreurs(formulaire);
        this.envoi = true;
        try {
            const reponse = await fetch(formulaire.action, {
                method: 'POST',
                // Le bouton cliqué est envoyé (« Enregistrer et soumettre » ou « Enregistrer en brouillon »).
                body: new FormData(formulaire, bouton?.name ? bouton : undefined),
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (reponse.status === 422) {
                const donnees = await reponse.json();
                this.erreur = this.$root.dataset.messageCorriger;
                this.afficherErreurs(formulaire, donnees.errors ?? {});
                return;
            }
            if (reponse.status === 403) {
                this.erreur = this.$root.dataset.messageInterdit;
                return;
            }
            if (!reponse.ok) {
                this.erreur = this.$root.dataset.messageErreur;
                return;
            }
            const type = reponse.headers.get('Content-Type') ?? '';
            let html;
            let url = reponse.url;
            if (type.includes('text/html')) {
                html = await reponse.text();
            } else if (type.includes('application/json')) {
                // Réponse JSON (préférence enregistrée…) : la page courante est relue.
                if (formulaire.querySelector('[name="theme"]')) {
                    window.location.reload();
                    return;
                }
                url = window.location.href;
                html = await (await fetch(url, { credentials: 'same-origin' })).text();
            } else {
                // Téléchargement ou réponse inattendue : envoi classique.
                formulaire.submit();
                return;
            }
            const focusId = this.origine?.id;
            this.fermer();
            remplacerContenu(html, url, focusId);
        } catch {
            this.erreur = this.$root.dataset.messageErreur;
        } finally {
            this.envoi = false;
        }
    },
}));

// Fenêtre chargée depuis le serveur : lien [data-fenetre-url] (« Modifier » sur une ligne de tableau).
document.addEventListener('click', async (evenement) => {
    const lien = evenement.target.closest('[data-fenetre-url]');
    if (!lien || evenement.ctrlKey || evenement.metaKey || evenement.shiftKey) {
        return;
    }
    evenement.preventDefault();
    try {
        const reponse = await fetch(lien.dataset.fenetreUrl, { headers: { 'X-Fenetre': '1', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        if (!reponse.ok) {
            throw new Error(String(reponse.status));
        }
        let hote = document.getElementById('fenetres-distantes');
        if (!hote) {
            hote = document.createElement('div');
            hote.id = 'fenetres-distantes';
            document.body.appendChild(hote);
        }
        window.raagaFenetreOrigine = lien;
        hote.innerHTML = await reponse.text();
        hote.addEventListener('fenetre-fermee', () => { hote.innerHTML = ''; }, { once: true });
    } catch {
        window.location.href = lien.href;
    }
});

/*
 * Accueil public : apparition douce des sections au défilement et comptage animé des chiffres clés
 * (IntersectionObserver, sans bibliothèque). Rien n'est animé si l'utilisateur réduit les animations.
 */
(() => {
    const sections = document.querySelectorAll('[data-apparition]');
    const compteurs = document.querySelectorAll('[data-compteur]');
    if ((!sections.length && !compteurs.length) || !('IntersectionObserver' in window)) {
        return;
    }
    const reduit = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    document.documentElement.classList.add('js');

    const apparition = new IntersectionObserver((entrees) => {
        entrees.forEach((entree) => {
            if (entree.isIntersecting) {
                entree.target.classList.add('est-visible');
                apparition.unobserve(entree.target);
            }
        });
    }, { rootMargin: '0px 0px -8% 0px' });
    sections.forEach((section) => apparition.observe(section));

    if (reduit) {
        return;
    }
    const format = new Intl.NumberFormat('fr-FR');
    const compter = (element) => {
        const cible = Number(element.dataset.compteur);
        const debut = performance.now();
        const duree = 1200;
        const pas = (instant) => {
            const t = Math.min(1, (instant - debut) / duree);
            const valeur = Math.round(cible * (1 - (1 - t) ** 3));
            element.textContent = format.format(valeur).replace(/ /g, ' ');
            if (t < 1) {
                requestAnimationFrame(pas);
            }
        };
        requestAnimationFrame(pas);
    };
    const comptage = new IntersectionObserver((entrees) => {
        entrees.forEach((entree) => {
            if (entree.isIntersecting) {
                compter(entree.target);
                comptage.unobserve(entree.target);
            }
        });
    });
    compteurs.forEach((compteur) => comptage.observe(compteur));
})();
})();

/*
 * Notifications en popup (<dialog data-notification>, composant x-notification) : affichées l'une après l'autre ; focus
 * sur « Compris » ; Échap ferme (comportement natif du dialogue modal). Succès et information (data-fermeture-auto) se
 * ferment seuls après 4 secondes ou au clic ; erreur et alerte restent ouvertes jusqu'à « Compris » ou Échap.
 */
(() => {
    const afficher = () => {
        const file = [...document.querySelectorAll('dialog[data-notification]')];
        const suivante = () => {
            const dialogue = file.shift();
            if (!dialogue || typeof dialogue.showModal !== 'function') {
                return;
            }
            dialogue.showModal();
            dialogue.querySelector('[data-notification-fermer]')?.focus();
            const delai = Number(dialogue.dataset.fermetureAuto || 0);
            const minuteur = delai > 0 ? setTimeout(() => dialogue.close(), delai) : null;
            if (delai > 0) {
                dialogue.addEventListener('click', () => dialogue.close(), { once: true });
            }
            dialogue.addEventListener('close', () => {
                clearTimeout(minuteur);
                suivante();
            }, { once: true });
        };
        suivante();
    };

    /*
     * Boutons Retour et Suivant (composant x-navigation-historique). Retour : history.back(), ou la page parente du fil
     * d'Ariane quand la page a été ouverte directement (aucune page précédente). Suivant : history.forward(), grisé
     * quand le navigateur indique qu'il n'y a pas de page suivante (Navigation API, quand elle est disponible).
     */
    const navigationHistorique = () => {
        const api = window.navigation;
        document.querySelectorAll('[data-historique-retour]').forEach((bouton) => {
            bouton.addEventListener('click', () => {
                const precedente = api && 'canGoBack' in api ? api.canGoBack : window.history.length > 1;
                if (precedente) {
                    window.history.back();
                } else {
                    window.location.href = bouton.dataset.parent;
                }
            });
        });
        const suivants = [...document.querySelectorAll('[data-historique-suivant]')];
        const actualiser = () => {
            const possible = !(api && 'canGoForward' in api) || api.canGoForward;
            suivants.forEach((bouton) => {
                bouton.disabled = !possible;
                bouton.setAttribute('aria-disabled', possible ? 'false' : 'true');
            });
        };
        suivants.forEach((bouton) => bouton.addEventListener('click', () => window.history.forward()));
        actualiser();
        api?.addEventListener?.('currententrychange', actualiser);
        window.addEventListener('pageshow', actualiser);
    };

    const demarrer = () => {
        afficher();
        navigationHistorique();
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', demarrer, { once: true });
    } else {
        demarrer();
    }
})();
