# Fiche de résolution — US-6.2 Mot de passe oublié

## 1. Incompatibilité type de clé : convention documentée vs schéma réel
- **Contexte** : création de l'entité `ResetPasswordRequest` et de sa clé étrangère vers `utilisateur`.
- **Symptôme attendu** : la convention de référence du projet mentionnait des clés `BIGINT UNSIGNED`.
- **Diagnostic** : lecture des migrations existantes (`utilisateur`, `creneau`, `journal_admin`) → toutes les
  tables utilisent en réalité `id INT AUTO_INCREMENT`. Une FK `BIGINT UNSIGNED → INT` est rejetée par MySQL
  (les types doivent correspondre).
- **Cause racine** : dérive documentaire — une convention écrite jamais appliquée dans le schéma.
- **Solution** : conserver `INT` pour préserver l'intégrité référentielle et rester homogène avec l'existant ;
  correction de la documentation planifiée.
- **Enseignement** : le schéma réel fait foi sur une note de convention ; une FK se valide sur le type de la
  colonne référencée, pas sur une intention.

## 2. Email non détecté en test : routage Messenger asynchrone
- **Contexte** : tests fonctionnels du déclenchement d'email de réinitialisation.
- **Symptôme** : aucun email « envoyé » détecté en test alors que le parcours fonctionne.
- **Diagnostic** : absence d'override Messenger en environnement de test → héritage du routage global
  `SendEmailMessage → async`. L'email n'est pas envoyé mais mis en file ; `assertEmailCount` compte donc 0.
- **Solution** : utiliser `assertQueuedEmailCount`. Découverte connexe : `APP_MAILER_REDIRECT_TO` est actif
  en test (chargement de `.env.local`) → l'assertion du destinataire a été rendue tolérante à l'adresse de
  redirection.
- **Enseignement** : la stratégie d'assertion mail dépend du transport effectif de l'environnement de test ;
  un test mail « vert » trop facilement peut masquer une assertion inadaptée.

## 3. Erreur de validation invisible sur un champ composé
- **Contexte** : garde « nouveau mot de passe ≠ actuel », erreur attachée au champ `plainPassword`
  (un `RepeatedType`, donc champ composé).
- **Symptôme** : le rejet fonctionnait (jeton préservé, pas de changement) mais aucun message ne s'affichait.
- **Cause racine** : le template ne rendait que les sous-champs `first`/`second` et les erreurs racine, pas
  les erreurs du champ parent composé.
- **Solution** : ajout de `form_errors(resetForm.plainPassword)` sans déplacer la cible du `addError`.
- **Enseignement** : une règle de sécurité dont l'erreur n'est pas rendue est inutile côté utilisateur ;
  la position d'une erreur de formulaire doit correspondre à ce que le gabarit affiche réellement.