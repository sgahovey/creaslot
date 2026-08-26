# Procédure de nommage et conventions de codage

> Document rédigé le 20 août 2026. Il formalise les conventions appliquées depuis le début
> du projet et vérifiées en continu par PHP-CS-Fixer, dont la configuration est versionnée
> depuis le 7 juin 2026.

Toutes les règles ci-dessous décrivent l'état réel du code, mesuré sur le dépôt. Les chiffres
cités sont des comptages, pas des estimations. Les écarts connus sont listés en section 9.

---

## 1. Norme de référence

Le style est celui de **PSR-12**, complété par le jeu de règles **@Symfony**. Les deux sont
activés dans `.php-cs-fixer.dist.php`, lignes 43 et 44 :

```php
'@PSR12'   => true,
'@Symfony' => true,
```

**Périmètre** : `src/` et `tests/` uniquement, déclaré lignes 17 à 21. Sont exclus par
construction `var/`, `vendor/`, `config/`, `public/` et `migrations/`, les migrations Doctrine
générées étant laissées telles quelles.

**Aucune règle risky** : `setRiskyAllowed(false)`, ligne 41. Seul du formatage sûr est appliqué,
aucune règle ne peut modifier la sémantique du code.

### Les cinq déviations assumées par rapport à @Symfony

| Règle | Valeur retenue | Motif |
|---|---|---|
| `php_unit_method_casing` | `snake_case` | phrases françaises, illisibles en camelCase (cf. section 5) |
| `concat_space` | `one` | concaténation espacée, `'a' . $b`, comme tout le code |
| `yoda_style` | `false` | conditions en ordre naturel, `$x !== 'now'` |
| `binary_operator_spaces` | `=>` aligné | alignement omniprésent, lisibilité des tableaux |
| `trailing_comma_in_multiline` | `arrays`, `arguments`, `parameters`, `match` | virgule finale déjà présente dans le code |

Chacune de ces déviations est justifiée par écrit dans l'en-tête du fichier de configuration,
lignes 23 à 39, de sorte que le motif reste attaché à la règle.

---

## 2. Nommage du code

| Élément | Convention | Exemple réel |
|---|---|---|
| Classe | PascalCase, un fichier par classe | `ReservationService` dans `src/Service/ReservationService.php` |
| Méthode | camelCase, verbe français en tête | `motifRefusPrealable()`, `src/Service/ReservationService.php:40` |
| Propriété | camelCase | `$dateDebut`, `src/Entity/Creneau.php:38` |
| Colonne de base | snake_case, déclarée explicitement | `#[ORM\Column(name: 'date_debut')]`, `src/Entity/Creneau.php:37` |
| Constante | UPPER_SNAKE, typée | `public const string VIEW`, `src/Security/ReservationVoter.php:26` |
| Cas d'énumération | UPPER_SNAKE | `case SUPER_ADMIN`, `src/Enum/RoleUtilisateur.php:15` |

**Correspondance fichier et classe** : sur les **102 fichiers PHP** de `src/`, **102** déclarent
une classe, une interface, un trait ou une énumération portant exactement le nom du fichier.
L'autoloading PSR-4 sur le namespace `App\` est donc vérifié sans exception.

**Typage strict** : `declare(strict_types=1);` est présent dans **101 des 102 fichiers**. La
seule exception est documentée en section 9.

---

## 3. Langue

Le métier s'écrit en français, la technique en anglais. La frontière passe **entre le nom et
le suffixe** : le nom porte le concept métier, le suffixe porte le rôle technique.

**Métier en français**, deux exemples :

- `SlotService::detecteChevauchements()`, `src/Service/SlotService.php:27`
- `ReservationService::reserver()`, `src/Service/ReservationService.php:94`

Les entités portent les mêmes noms que le domaine : `Creneau`, `Reservation`, `Utilisateur`,
`TypeRdv`.

**Technique en anglais**, deux exemples :

- les suffixes de rôle, `Repository`, `Voter`, `Controller`, `Service`
- les méthodes imposées par le framework, `supports()` et `voteOnAttribute()` dans les voters,
  `getNonce()` dans `src/Security/Csp/CspNonceProvider.php`

**La frontière**, deux exemples : `AnonymisationCompteService` et `CreneauRepository`. Nom
français, suffixe anglais, jamais de mélange à l'intérieur d'un même segment.

---

## 4. Suffixes par rôle

Le suffixe d'une classe annonce son rôle. Comptages par répertoire :

| Suffixe | Nombre | Répertoire | Fichiers PHP du répertoire |
|---|---:|---|---:|
| `*Service.php` | 11 | `src/Service/` | 13 |
| `*Repository.php` | 8 | `src/Repository/` | 8 |
| `*Controller.php` | 24 | `src/Controller/` | 25 |
| `*Voter.php` | 3 | `src/Security/` | 6 |
| `*Exception.php` | 4 | `src/` | 102 |
| `*Type.php` | 12 | `src/Form/` | 13 |

Les fichiers qui ne portent pas le suffixe majoritaire de leur répertoire portent un autre
suffixe de rôle, tout aussi explicite, et non l'absence de suffixe :

- `CreneauCalendarSerializer` et `OccupationCalendarSerializer` dans `src/Service/`, suffixe
  `Serializer`
- `JsonSansCacheTrait` dans `src/Controller/Traits/` et `ProtectionCsrfTrait` dans `src/Form/`,
  suffixe `Trait`
- `UserChecker` et `CspNonceProvider` dans `src/Security/`, suffixes `Checker` et `Provider`

Les contrôleurs sont en outre rangés par rôle applicatif : `src/Controller/Admin/`, `Api/`,
`Auditeur/`, `Personnel/`.

---

## 5. Méthodes de test

Une méthode de test se nomme `test_` suivi d'une phrase française en snake_case, qui décrit le
comportement attendu et non la méthode appelée.

```php
public function test_auditeur_ne_peut_pas_annuler_reservation_dun_autre(): void
public function test_api_creneaux_refuse_l_acces_a_un_auditeur(): void
```

**Mesure** : la suite compte **339 méthodes de test**, dont **339** respectent cette forme, soit
la totalité.

**Pourquoi contre le camelCase de @Symfony.** Le nom d'un test est une phrase, pas un identifiant
de méthode ordinaire : il est lu par un humain dans un rapport d'exécution, pas appelé depuis du
code. `test_auditeur_ne_peut_pas_annuler_reservation_dun_autre` se lit d'un trait, là où
`testAuditeurNePeutPasAnnulerReservationDunAutre` demande un effort de découpage à chaque
lecture. La règle `php_unit_method_casing` est donc réglée sur `snake_case`, ce qui rend la
convention vérifiable automatiquement au lieu de reposer sur la discipline.

---

## 6. Base de données

- **Tables en snake_case singulier.** Les huit tables portées par une entité :
  `creneau`, `journal_admin`, `notification`, `reservation`, `reset_password_request`,
  `service`, `type_rdv`, `utilisateur`. Une neuvième table applicative, `historique_utilisateur`,
  suit la même règle.
- **Clés étrangères préfixées `id_`.** Les huit colonnes de jointure déclarées dans les entités :
  `id_creneau`, `id_destinataire`, `id_reservation`, `id_service`, `id_type_rdv`, et
  `id_utilisateur` employée trois fois.
- **Clés primaires `INT AUTO_INCREMENT`.** Neuf tables des migrations déclarent
  `id INT AUTO_INCREMENT NOT NULL`. Seule `messenger_messages` fait exception, avec un `BIGINT` :
  cette table est créée par Symfony Messenger et n'est pas sous notre contrôle.

---

## 7. Templates et routes

**Templates Twig.** Noms en snake_case, jamais de tiret : sur **62 fichiers** `.html.twig`,
**aucun** ne contient de tiret. Exemples : `carte_creneau_disponible.html.twig`,
`base_admin.html.twig`.

L'organisation distingue trois natures de template :

- `templates/_partials/` pour les fragments inclus dans le gabarit, 4 fichiers dont
  `header.html.twig` et `flash_messages.html.twig` ; c'est le **répertoire** qui porte le tiret
  bas, pas les fichiers
- `templates/components/` pour les composants paramétrables réutilisés, comme
  `carte_reservation.html.twig`
- les autres répertoires par rôle, `admin/`, `auditeur/`, `personnel/`, `auth/`, `emails/`

Deux fichiers seulement portent un tiret bas initial, `templates/emails/_layout.html.twig` et
`templates/export/_donnees.html.twig`, tous deux gabarits internes à leur répertoire.

**Routes.** Nom préfixé `app_` pour l'applicatif, `api_` pour les points d'entrée JSON. Chemin en
kebab-case français.

**Mesure** : **48 routes nommées**, dont **46** en `app_` et **2** en `api_`, soit la totalité.
Exemples : `app_mes_donnees` sur `/mes-donnees`, `api_creneaux_personnel` sur `/creneaux`.

---

## 8. Vérification

La conformité n'est pas déclarative, elle est exécutable :

```bash
docker compose exec app vendor/bin/php-cs-fixer fix --dry-run --using-cache=no
```

Résultat au 20 août 2026 :

```
PHP CS Fixer 3.95.4 Adalbertus
Found 0 of 162 files that can be fixed in 1.101 seconds
```

**162 fichiers analysés, zéro écart.** Le code de retour est 0. Cette commande tourne également
dans le pipeline d'intégration continue, où elle bloque la fusion en cas d'écart, ce qui rend la
convention opposable et non facultative.

---

## 9. Écarts connus et assumés

Cinq écarts subsistent. Ils sont connus, localisés, et laissés en l'état pour les motifs
ci-dessous.

| Écart | Emplacement | Motif |
|---|---|---|
| `declare(strict_types=1)` absent | `src/Kernel.php` | Squelette généré par Symfony, jamais modifié depuis. Seul fichier concerné sur 102. |
| Deux classes de formulaire en anglais | `src/Form/ChangePasswordFormType.php:24` et `src/Form/ResetPasswordRequestFormType.php` | Générées par SymfonyCasts ResetPasswordBundle. Les renommer romprait la correspondance avec la documentation du bundle. Elles cohabitent avec la version française `ChangementMotDePasseType`, `src/Form/ChangementMotDePasseType.php:29`. |
| Entité en anglais | `src/Entity/ResetPasswordRequest.php:25` | Nom imposé par l'interface `ResetPasswordRequestInterface` du même bundle. Seule entité anglaise sur huit. |
| Abréviation dans un nom de méthode | `src/Service/DashboardService.php:38`, `calculerKpis()` | Contredit la règle d'absence d'abréviation. Le terme est employé tel quel dans le vocabulaire du tableau de bord. |
| Segment d'URL en anglais | `src/Controller/Api/CreneauApiController.php:40`, `/creneaux/next-reserved` | Seule URL non française sur 48 routes. Point d'entrée technique consommé par le calendrier, jamais affiché à l'utilisateur. |

Aucun de ces écarts n'est détectable par PHP-CS-Fixer : ils portent sur le choix des noms, pas
sur le formatage. Ils ont été trouvés par relecture ciblée du dépôt, et c'est précisément la
raison pour laquelle ils figurent ici.
