# ISPAG CRM 👥

Gestion de la **relation client et fournisseur** d'ISPAG, intégrée à WordPress : entreprises, contacts, offres (deals), activités et automatisations.

## Fonctionnalités

### Entreprises et contacts
- Fiches entreprise et contact (clients, fournisseurs, bureaux d'ingénieurs) avec édition en ligne, listes filtrables et création rapide.
- Onglet **Fournisseur** : devise, TVA, **langue** (liste de choix), délais, contacts de commande, de plans, de facturation et de livraison, articles fournis avec leur prix d'achat.
- Contrôle des doublons (e-mail, site web) et synchronisation des contacts (CardDAV / Baïkal).
- Cycle de vie des contacts (leads, clients), santé des contacts et rapprochement automatique.

### Offres et projets
- Kanban et liste des offres par étape, export, modification groupée, motifs de refus, rapports hebdomadaires et des offres perdues.
- Les cartes entreprise et contacts d'un projet ne sont visibles qu'avec les droits `view_company` / `view_contact`.

### Activités et communication
- Notes, appels, e-mails, tâches et réunions sur chaque fiche ; cartes d'activité compactes.
- Modèles de messages et séquences, e-mails via Brevo / Mailgun, signature, rappels de tâches, notifications (navigateur, e-mail).
- **WhatsApp** : journalisation des messages envoyés et reçus (passerelle Node dans `whatsapp-bridge/`, plusieurs numéros).
- Intégrations : Typeform, raccourci iPhone, Mistral / Gemini (aide à la saisie), webhooks.

### Documents
- Pièces jointes par entreprise, contact ou projet, avec types de documents et dépôt par glisser-déposer.

## Installation
1. Activer ISPAG Project Manager en premier, puis ISPAG CRM ; les tables et pages sont créées à l'activation.
2. Reprise de données : scripts de migration dans `migrations/` (fournisseurs vers entreprises).
3. Attribuer les droits CRM (`view_company`, `edit_company`, `add_company`, `view_contact`, `add_contact`, `manage_templates`) dans l'écran « ISPAG Rights ».
4. Clé Mistral et réglages WhatsApp : pages de réglages d'ISPAG.

Mise à jour automatique depuis GitHub ; traductions FR / DE dans `languages/`.

---
© 2026 ISPAG - All Rights Reserved
