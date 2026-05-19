# Dette technique CreaSlot — Suivi

Date dernière mise à jour : 18 mai 2026.
Convention : DT-N = Dette Technique numéro N.

---

## DT-1 — Architecture OneToOne Creneau↔Reservation (🔴 CRITIQUE)

**Détecté** : 18/05/2026, lors validation US-4.6 (smoke test cron rappel J-1).

**Symptôme** :
- Erreur HTTP 500 "Duplicate entry '38' for key 'reservation.UNIQ_42C8495527FB222F'"
- Cas reproductible : annuler une réservation puis tenter d'en re-créer une sur le même créneau côté Auditeur

**Cause racine** :
- `Reservation::$creneau` est en `OneToOne(unique: true)` (cf. Entity/Reservation.php L21-23)
- L'annulation est un soft-delete (statut → ANNULEE, dateAnnulation/motifAnnulation peuplés)
- La ligne reste en BDD → impossible d'insérer une 2e Reservation sur le même créneau

**Incohérence design** :
- Intention métier (champs dateAnnulation/motifAnnulation) = "préserver historique"
- Contrainte schéma OneToOne = "1 seule Reservation par Creneau, à vie"

**Stratégie de fix retenue** : S4 — Migration vers OneToMany
- `Reservation::$creneau` → ManyToOne (drop unique sur join column)
- `Creneau::$reservation` → Collection au lieu de ?Reservation
- Unicité de la Reservation ACTIVE garantie applicatif via PESSIMISTIC_WRITE déjà en place

**Branche prévue** : `bugfix/reservation-onetomany-creneau` (sortie develop frais)
**Effort estimé** : 3-4h sur session dédiée
**Priorité** : 🔴 haute, à traiter APRÈS merge US-4.6 et AVANT US-4.7

---

## DT-2 — Validation horaire créneau manquante (🔴 ÉLEVÉ)

**Détecté** : 18/05/2026, lors validation visuelle email US-4.6.

**Symptôme** : Email rappel J-1 affichait "Horaire : 10h00 – 02h00" pour un créneau test.

**Cause racine précisée par user** :
Le formulaire de création de créneau (option "Personnaliser l'heure de fin", probablement dans `CreneauType`) accepte une heure de fin antérieure à l'heure de début sans validation.

**Reproduction** :
1. Connexion Personnel
2. Créer un créneau
3. Cocher "Personnaliser l'heure de fin"
4. Saisir une heure < heure de début (ex : début 10h00, fin 02h00)
5. ✅ Soumission acceptée → créneau incohérent en BDD

**Impact** :
- Créneau incohérent affiché dans agendas (auditeur + personnel)
- Emails (US-4.2 à US-4.6) reproduisent l'absurdité
- N'importe quel Personnel peut produire ce bug en production

**Stratégie de fix proposée** : Defense in depth (3 niveaux)

1. **Niveau UI (HTML5)** : `<input type="time" min="...">` sur le champ heure de fin
2. **Niveau Symfony Form** : Contrainte `Callback` dans `CreneauType` comparant dateDebut/dateFin
3. **Niveau Entité** : `#[Assert\Callback]` sur `Creneau::validerHoraires()` 

Recommandation : appliquer les 3 niveaux (defense in depth, best practice Symfony).

**Branche prévue** : `bugfix/validation-horaire-creneau` (sortie develop frais)
**Effort estimé** : ~1h
**Priorité** : 🔴 élevée (UX prod), à traiter idéalement avec DT-1 (même domaine entity Creneau)

---

## DT-3 — PHPUnit Notices willReturnCallback (🟢 BAS)

**Détecté** : 18/05/2026, baseline US-4.2 à US-4.6.

**Symptôme** : 30 PHPUnit Notices à l'exécution (pattern willReturnCallback dans helpers de tests).

**Stratégie de fix** : refacto progressif vers willReturn (cas simples) ou expectations strictes (cas complexes).

**Priorité** : 🟢 basse, cosmétique, n'impacte pas la production.

---

## DT-4 — Dockerfile USER non-root (🟢 BAS)

**Détecté** : 18/05/2026, incident permissions Git WSL2.

**Symptôme** : Dossier .git/objects/01/ à root après opérations Docker → "error: insufficient permission".

**Workaround actuel** : `sudo chown -R utilisateur:utilisateur ~/creaslot/.git/` préventif.

**Stratégie de fix** : Dockerfile `USER 1000:1000` pour aligner UID avec WSL2.

**Priorité** : 🟢 basse, à faire avant déploiement prod (itération 6).

---

## DT-5 — `final` retiré de NotificationService pour testabilité (🟢 BAS)

**Détecté** : 19/05/2026, lors écriture EnvoyerRappelsJ1CommandTest.

**Contexte** : NotificationService était initialement déclaré `final readonly class` (best practice Symfony 8). L'écriture du test Command nécessitait de mocker NotificationService → PHPUnit\Framework\MockObject\ClassIsFinalException.

**Choix d'arbitrage** : drop `final`, garder `readonly`. NotificationService n'a pas vocation à être étendu dans l'architecture DI Symfony actuelle, le `final` était cosmétique.

**Alternative considérée** : extraction de `NotificationServiceInterface` (architecture plus propre via Dependency Inversion Principle). Reportée car scope creep par rapport à US-4.6.

**Stratégie future** :
- Quand US-4.7 (page Mes notifications) ou US-4.8 (préférences) sera traitée, envisager l'extraction de l'interface si plusieurs implémentations émergent
- Si pas de besoin futur, garder `readonly class` simple

**Priorité** : 🟢 basse, statu quo acceptable.
