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
/*
 * Soumission groupée à validation depuis une liste (<x-tableau> avec sélection) : cases des lignes à soumettre ou à
 * retirer, barre collante, contrôle préalable de chaque élément (✓ prêt ou ✗ raison) avant l'envoi des seuls éléments prêts.
 */
Alpine.data('soumissionGroupee', ({ controle }) => ({
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
        const lignesDefaut = this.$el.dataset.lignesDefaut ?? '25';
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
            this.$dispatch('tableau-charge');
            // Bouton « Exporter » de l'en-tête de page : il suit le résultat affiché.
            const exporter = this.$refs.resultats.querySelector('[data-tableau-resultats]')?.dataset.export;
            if (exporter) {
                document.querySelectorAll(`[data-export-tableau="${this.$el.dataset.tableau}"]`).forEach((lien) => { lien.href = exporter; });
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
Alpine.data('menuDeroulant', ({ deplie = false, cle = '', url = '' } = {}) => ({
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

Alpine.data('cadreAdmin', ({ theme = 'clair', urlTheme = '', urlLues = '' } = {}) => ({
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
Alpine.data('rechercheGlobale', () => ({
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

Alpine.data('fenetre', () => ({
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
                this.erreur = this.$el.dataset.messageCorriger;
                this.afficherErreurs(formulaire, donnees.errors ?? {});
                return;
            }
            if (reponse.status === 403) {
                this.erreur = this.$el.dataset.messageInterdit;
                return;
            }
            if (!reponse.ok) {
                this.erreur = this.$el.dataset.messageErreur;
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
            this.erreur = this.$el.dataset.messageErreur;
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

/*
 * Carrousel de l'accueil (sans bibliothèque) : glissement au doigt natif (scroll-snap), fondu à l'arrivée d'une
 * diapositive, lecture automatique toutes les 6 s suspendue au survol, au focus, onglet caché ou sur « Pause », jamais
 * lancée si l'utilisateur réduit les animations. Seule la diapositive visible est exposée (aria-hidden + inert).
 * Les images suivantes ne sont chargées qu'à l'approche de leur tour.
 */
document.querySelectorAll('[data-carrousel]').forEach((carrousel) => {
    const piste = carrousel.querySelector('[data-piste]');
    const diapos = [...carrousel.querySelectorAll('[data-diapo]')];
    const points = [...carrousel.querySelectorAll('[data-point]')];
    const lecture = carrousel.querySelector('[data-lecture]');
    const reduit = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let actuelle = 0;
    let enLecture = !reduit;
    let survol = false;
    let focus = false;
    let minuterie = null;

    const charger = (i) => {
        const image = diapos[(i + diapos.length) % diapos.length].querySelector('img[data-srcset]');
        if (image) {
            image.srcset = image.dataset.srcset;
            image.src = image.dataset.src;
            image.removeAttribute('data-srcset');
        }
    };

    const marquer = (i) => {
        actuelle = i;
        diapos.forEach((diapo, j) => {
            diapo.toggleAttribute('inert', j !== i);
            if (j === i) {
                diapo.removeAttribute('aria-hidden');
            } else {
                diapo.setAttribute('aria-hidden', 'true');
            }
        });
        points.forEach((point, j) => point.setAttribute('aria-current', j === i ? 'true' : 'false'));
        charger(i);
        charger(i + 1);
    };

    const afficher = (i, fondu = true) => {
        i = (i + diapos.length) % diapos.length;
        if (i === actuelle) {
            return;
        }
        charger(i);
        piste.scrollTo({ left: i * piste.clientWidth, behavior: 'instant' });
        if (fondu && !reduit) {
            diapos[i].classList.remove('est-entrante');
            void diapos[i].offsetWidth;
            diapos[i].classList.add('est-entrante');
        }
        marquer(i);
        relancer();
    };

    const actif = () => enLecture && !survol && !focus && !document.hidden;

    function relancer() {
        clearInterval(minuterie);
        minuterie = actif() ? setInterval(() => afficher(actuelle + 1), 6000) : null;
        // Défilement automatique : pas d'annonce ; à l'arrêt, chaque changement est annoncé.
        piste.setAttribute('aria-live', actif() ? 'off' : 'polite');
    }

    const majLecture = () => {
        lecture.setAttribute('aria-label', enLecture ? lecture.dataset.libellePause : lecture.dataset.libelleLecture);
        lecture.querySelector('[data-icone-pause]').toggleAttribute('hidden', !enLecture);
        lecture.querySelector('[data-icone-lecture]').toggleAttribute('hidden', enLecture);
        relancer();
    };

    lecture.addEventListener('click', () => {
        enLecture = !enLecture;
        majLecture();
    });
    carrousel.querySelector('[data-precedente]').addEventListener('click', () => afficher(actuelle - 1));
    carrousel.querySelector('[data-suivante]').addEventListener('click', () => afficher(actuelle + 1));
    points.forEach((point) => point.addEventListener('click', () => afficher(Number(point.dataset.point))));

    carrousel.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
            e.preventDefault();
            afficher(actuelle + (e.key === 'ArrowRight' ? 1 : -1));
        }
    });
    carrousel.addEventListener('mouseenter', () => { survol = true; relancer(); });
    carrousel.addEventListener('mouseleave', () => { survol = false; relancer(); });
    carrousel.addEventListener('focusin', () => { focus = true; relancer(); });
    carrousel.addEventListener('focusout', (e) => {
        if (!carrousel.contains(e.relatedTarget)) {
            focus = false;
            relancer();
        }
    });
    document.addEventListener('visibilitychange', relancer);

    // Glissement au doigt : la diapositive atteinte devient la diapositive courante à la fin du défilement.
    let attente = null;
    piste.addEventListener('scroll', () => {
        charger(Math.ceil(piste.scrollLeft / piste.clientWidth));
        clearTimeout(attente);
        attente = setTimeout(() => {
            const i = Math.round(piste.scrollLeft / piste.clientWidth);
            if (i !== actuelle) {
                marquer(i);
                relancer();
            }
        }, 120);
    }, { passive: true });
    window.addEventListener('resize', () => piste.scrollTo({ left: actuelle * piste.clientWidth, behavior: 'instant' }));

    // Diapositive suivante préparée une fois la page chargée.
    if (document.readyState === 'complete') {
        charger(1);
    } else {
        window.addEventListener('load', () => charger(1), { once: true });
    }
    majLecture();
});

window.Alpine = Alpine;

Alpine.start();
