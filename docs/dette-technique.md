# Dette technique CreaSlot — Suivi

Date dernière mise à jour : 27 mai 2026.
Convention : DT-N = Dette Technique numéro N.

---

## DT-1 — Architecture OneToOne Creneau↔Reservation (🔴 CRITIQUE) — ✅ RESOLVED 27/05/2026

> **✅ RESOLVED le 27/05/2026** sur branche `bugfix/reservation-onetomany-creneau`.
>
> **Résumé fix** : Refacto vers OneToMany (Stratégie S4 retenue). Migration Doctrine `Version20260527155759` drop l'index UNIQUE sur `reservation.id_creneau` et le remplace par un index non-unique. L'invariant "1 Reservation ACTIVE max par Creneau" est désormais garanti applicatif via le `PESSIMISTIC_WRITE` dans `ReservationController::enregistrerReservation`.
>
> **Validations** :
> - ✅ 66/66 tests verts (65 existants + 1 nouveau test d'intégration `tests/Integration/ReservationRereservationApresAnnulationTest.php` qui fige la non-régression)
> - ✅ Smoke E2E manuel : Auditeur réserve → annule → re-réserve un créneau, le scénario qui causait HTTP 500 fonctionne désormais
>
> **Sous-correctifs notables** :
> - R7 (`CreneauRepository::findDisponibles`) : conversion `LEFT JOIN + (r.id IS NULL OR r.statut != ACTIVE)` en `NOT EXISTS` (anti-régression OneToMany — sans cela, un créneau avec `[ACTIVE + ANNULEE]` apparaîtrait à tort disponible)
> - Refacto signature `NotificationService::notifierAuditeurSuppressionCreneau(Creneau $c, Reservation $r)` : élimine le workaround documenté en PHPDoc (passage explicite de la Reservation par le caller, post-annulation)
> - Premier test d'intégration `KernelTestCase` du projet — pattern réutilisable pour futurs cas (a généré [[DT-6]])
> - Cleanup `findDisponiblesParUtilisateur` (méthode morte 0 consommateur)
>
> **Fichiers impactés** : 9 fichiers modifiés + 1 migration + 1 test d'intégration créé.

---

### Contenu historique original (préservé pour traçabilité MSP3)

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

---

## DT-6 — Setup BDD test à automatiser (🟢 BAS)

**Détecté** : 27/05/2026, lors mise en place du 1er test d'intégration (cf. [[DT-1]]).

**Contexte** : La création de la BDD `creaslot_test` + GRANT user est actuellement manuelle one-shot, à rejouer après chaque `docker compose down -v` ou sur tout nouveau clone du repo :

```sql
CREATE DATABASE IF NOT EXISTS creaslot_test;
GRANT ALL PRIVILEGES ON creaslot_test.* TO 'creaslot'@'%';
FLUSH PRIVILEGES;
```

Puis :

```bash
docker compose exec app php bin/console doctrine:migrations:migrate -n --env=test
```

Sans ce setup, tout test d'intégration extending `KernelTestCase` échoue avec « Access denied for user 'creaslot'@'%' to database 'creaslot_test' » (le `dbname_suffix: '_test%env(default::TEST_TOKEN)%'` de `config/packages/doctrine.yaml` sous `when@test` ajoute le suffix `_test` à la BDD).

**Stratégie de fix proposée** :

- **Option A** : Script `bin/setup-test-db.sh` à exécuter une fois après `git clone`
- **Option B** : Commande Symfony custom `app:setup-test-db` (intégrée dans un `Makefile`)
- **Option C** : `init.sql` exécuté au démarrage du conteneur MySQL (via volume monté dans `/docker-entrypoint-initdb.d/`)

**Recommandation** : Option C (init MySQL au démarrage) — totalement transparent pour le dev, aucune commande supplémentaire à mémoriser. Option B si on veut plus de contrôle (ex : truncate sélectif entre suites).

**Priorité** : 🟢 basse, à faire avant si plusieurs devs rejoignent le projet OU avant déploiement CI/CD.
