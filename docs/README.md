# Par où commencer

CreaSlot est une application de prise de rendez-vous pour le Cnam Réunion. Les étudiants,
appelés auditeurs, y réservent un créneau proposé par le personnel administratif, en
présentiel, par téléphone ou en visioconférence. Elle remplace une organisation qui reposait
sur des courriels et des appels, sans vue partagée des disponibilités.

Elle est en service sur [creaslot.re](https://creaslot.re).

Ce répertoire contient la documentation du projet. Cette page dit où regarder selon ce que
vous cherchez.

---

## Où regarder, selon ce que vous cherchez

| Vous voulez savoir | Lisez |
|---|---|
| **Comment le projet a été piloté**, et ce qui a été décidé en cours de route | [`dette-technique.md`](dette-technique.md), 48 entrées datées : chacune porte un défaut ou un compromis, sa cause, sa résolution, et le commit qui la corrige. Six sont closes sans une ligne de code, par décision argumentée. |
| **Comment l'application est déployée**, et ce qu'on fait quand ça casse | [`runbook-deploiement.md`](runbook-deploiement.md), dix sections de procédures copiables : promotion, mise en service, sauvegarde, restauration, retour arrière, incidents rencontrés. |
| **Comment la sécurité a été traitée** | [`audit-securite-owasp.md`](audit-securite-owasp.md), une ligne par catégorie du référentiel public de risques applicatifs, avec ce qui a été fait, ce qui reste partiel et pourquoi. |
| **Comment l'application est testée**, et si la couverture est sérieuse | [`plan-de-tests.md`](plan-de-tests.md) : la stratégie à trois niveaux, la matrice qui relie chaque exigence à ses fichiers de tests, et les résultats. |
| **Comment le code est écrit**, conventions de nommage comprises | [`procedure-de-nommage.md`](procedure-de-nommage.md), y compris les cinq écarts assumés et la raison de chacun. |
| **Comment les machines sont organisées** | [`architecture-deploiement.md`](architecture-deploiement.md) : les environnements, le réseau, le proxy, et les décisions d'infrastructure avec leurs alternatives écartées. |
| **Ce que fait l'application, écran par écran** | [`conception/maquettes-lofi/`](conception/maquettes-lofi/) pour les intentions, [`realisation/maquettes-hifi/`](realisation/maquettes-hifi/) pour le rendu final. Ouvrez `index.html`. |
| **Comment les données sont structurées** | [`conception/diagrammes/`](conception/diagrammes/) : modèle physique, diagramme de classes, cas d'utilisation, diagrammes de séquence. |
| **Comment les tâches planifiées fonctionnent** | les trois documents `cron-*.md` : sauvegarde quotidienne, rappels la veille, purge du journal. |
| **Comment reproduire une figure du dossier** | [`realisation/diagrammes/README.md`](realisation/diagrammes/README.md), qui donne la commande exacte et distingue les figures gelées des figures vivantes. |
| **Comment le dossier rendu est structuré**, page par page | [`carte-dossier.md`](carte-dossier.md) : la cartographie du mémoire remis, sections, pages, figures et tableaux. Utile pour retrouver un passage sans ouvrir le document. |
| **Comment répondre à l'oral sur deux sujets précis** | [`preparation/`](preparation/) : les six familles de contraintes, et les formes normales démontrées sur les tables réelles. Aide-mémoire de soutenance, pas des livrables. |

---

## Un parcours de lecture, si vous ne savez pas par où entrer

1. **[`plan-de-tests.md`](plan-de-tests.md)**, section 4 : la matrice de traçabilité montre en une page ce que fait l'application et comment chaque fonction est vérifiée.
2. **[`audit-securite-owasp.md`](audit-securite-owasp.md)** : la même exhaustivité appliquée aux risques, avec les limites assumées écrites noir sur blanc.
3. **[`dette-technique.md`](dette-technique.md)**, une entrée au hasard : c'est là que se lit la façon de travailler, un défaut à la fois.
4. **[`runbook-deploiement.md`](runbook-deploiement.md)**, section 9 : ce qu'on fait quand la mise en service se passe mal.

Ces quatre documents se lisent en une demi-heure et couvrent la conception, la sécurité, le
pilotage et l'exploitation.

---

## Ce qui est figé, et ce qui a continué

Le dossier a été rendu le **7 août 2026**. Le projet a continué après.

**Figé au 5 août 2026**, deux jours avant le rendu, et à ne pas modifier :

- tout [`conception/`](conception/), 84 fichiers, dont les maquettes lo-fi et les diagrammes ;
- quatre figures de [`realisation/diagrammes/`](realisation/diagrammes/) : `charte-graphique`,
  `chaine-tracabilite`, `dispositif-veille` et `gantt-reel` ;
- [`plan-de-tests.md`](plan-de-tests.md), dont l'exécution de référence date du 05/08 et dont
  les chiffres décrivent le projet à cette date, non aujourd'hui.

**Vivant depuis**, tenu à jour :

- [`dette-technique.md`](dette-technique.md), qui a reçu sept entrées après le rendu ;
- [`audit-securite-owasp.md`](audit-securite-owasp.md), [`runbook-deploiement.md`](runbook-deploiement.md)
  et [`architecture-deploiement.md`](architecture-deploiement.md), suivis à mesure des livraisons ;
- trois figures ajoutées après le rendu, dont une version datée de la charte graphique qui
  coexiste avec l'originale sans la remplacer.

**La règle appliquée** : quand une figure du dossier devient obsolète, on en crée une version
datée à côté plutôt que de réécrire l'originale. L'écart entre les deux est documenté dans
l'en-tête de la nouvelle. Un lecteur peut ainsi comparer ce qui a été rendu et ce qui a suivi.
