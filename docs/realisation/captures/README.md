# Captures d'écran de l'application

Ce répertoire contient les captures de l'application réelle utilisées par le dossier et le
diaporama. Contrairement aux figures de `../diagrammes/`, une capture **n'a pas de source** :
l'image est le seul artefact. La reproduire suppose donc de reconstituer l'état qui l'a
produite, et c'est ce que ce fichier décrit.

Jusqu'ici, **aucune procédure n'était écrite**. Les deux captures présentes datent du
05/08/2026 et rien n'indiquait comment elles avaient été prises.

## Inventaire

| Fichier | Dimensions | Écran | Zone |
|---|---|---|---|
| `liste-des-creneaux-dispo.png` | 780 x 857 | Créneaux disponibles, Auditeur | de la barre de navigation à la pagination, pied de page exclu |
| `liste-des-creneaux-dispo-MOBILE.png` | 267 x 532 | idem, en colonne unique | du haut de page à la deuxième carte, coupée |

Les deux sont également incorporées au diaporama, à l'octet près (`ppt/media/image25.png` et
`image26.png`). **Toute reprise doit donc être répercutée dans les deux endroits.**

## Environnement de prise de vue

```bash
docker compose up -d
docker compose exec app php bin/console doctrine:migrations:migrate -n
docker compose exec app php bin/console doctrine:fixtures:load -n
docker compose exec app php bin/console cache:clear
docker compose exec app php bin/console asset-map:compile
```

L'application répond sur **http://localhost:8000**. Les comptes de démonstration partagent
le mot de passe `Motdepasse123!` (cf. `src/DataFixtures/DemoFixtures.php`).

`asset-map:compile` n'est pas optionnel : la configuration Docker ne sert pas les assets à la
volée, une charte non compilée ne serait pas celle affichée à l'écran.

**Contrôle préalable, à ne pas sauter** : vérifier que la feuille servie est bien celle du
dépôt, sans quoi la capture figerait une charte périmée.

```bash
curl -s http://localhost:8000/css/creaslot.css | diff - public/css/creaslot.css && echo identique
```

## État de données attendu

C'est le point qui coûte, et celui qu'aucune commande ne garantit.

La capture d'origine a été prise avec le compte **`creaslotdemo+julie@gmail.com`** (Julie
Potier, Auditrice), reconnaissable au « Bonjour, Julie » de la barre de navigation et au
badge de deux notifications non lues.

Elle montre **14 créneaux disponibles**, dont **12 cartes** en première page, la pagination
étant fixée à 12 par page (`CreneauRepository::findDisponibles`, argument `$limit`).

**Les fixtures actuelles ne reproduisent pas cet état** : elles produisent 10 créneaux, dont
7 disponibles à venir, soit une seule page et aucune pagination. Reprendre la capture à
l'identique suppose donc, au choix :

1. accepter un contenu différent, ce qui est le plus simple si la capture illustre une mise
   en page et non un chiffre ;
2. enrichir `DemoFixtures` le temps de la prise de vue, sans committer ce changement ;
3. créer les créneaux manquants par l'interface Personnel avant de capturer.

Les dates des créneaux étant relatives à la date d'exécution, elles différeront de toute
façon de celles de l'image d'origine.

## Prise de vue

Les captures d'origine ont été prises à la main, à la largeur d'affichage correspondant aux
dimensions du tableau ci-dessus. Pour un rendu reproductible, Chrome sans interface graphique
est disponible sous `~/.cache/puppeteer/chrome/<version>/chrome-linux64/chrome` et accepte
une fenêtre de dimensions fixées :

```bash
CHROME=~/.cache/puppeteer/chrome/linux-151.0.7922.47/chrome-linux64/chrome
"$CHROME" --headless --disable-gpu --hide-scrollbars \
  --window-size=780,857 --screenshot=sortie.png \
  http://localhost:8000/creneaux-disponibles
```

**Réserve, non levée** : cette voie exige une session authentifiée, donc un cookie de
connexion transmis au navigateur. La protection CSRF étant sans état (jeton en double
soumission posé par un contrôleur Stimulus), la connexion en ligne de commande n'est pas
immédiate. Tant que ce point n'est pas outillé, la prise de vue reste manuelle, navigateur
ouvert, après connexion à l'interface.

## Règle de gel

Ces deux captures datent du 05/08/2026, deux jours avant le rendu du dossier du 07/08/2026.
**Leur appartenance au dossier rendu n'est pas établie** : aucun PDF n'est versionné ici et
aucun document du dépôt ne les référence. Tant que ce point n'est pas tranché, appliquer la
règle du répertoire des diagrammes : en cas de reprise, **créer une version datée à côté**
plutôt que de remplacer l'originale, et documenter l'écart.
