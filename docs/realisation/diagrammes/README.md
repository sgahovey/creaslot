# Régénération des figures de la réalisation

Ce répertoire contient les figures du dossier, chacune sous deux formes : la **source**
(`.puml` ou `.mermaid`) et le **PNG** rendu, tous deux versionnés. Le PNG est ce que le
dossier affiche ; la source est ce qui fait foi.

Jusqu'ici, **aucune commande de régénération n'était écrite nulle part**. Elle ne survivait
que dans la mémoire de qui avait produit les figures. Ce fichier comble ce manque.

## PlantUML (`.puml`)

```bash
java -jar ~/plantuml.jar -tpng docs/realisation/diagrammes/<figure>.puml
```

Le PNG est écrit à côté de la source, sous le même nom.

**Le jar n'est pas versionné**, volontairement : 29,5 Mo pour un usage ponctuel. Il vit
hors du dépôt, dans `~/plantuml.jar`. Version utilisée pour les figures actuelles :
**PlantUML 1.2026.6**, sous **OpenJDK 25**. À défaut, `https://plantuml.com/download`.

**Cette commande est vérifiée, pas supposée** : appliquée à `charte-graphique.puml`, elle
reproduit le PNG versionné **au bit près** (empreinte SHA-256
`c7b08a18c13a15cd1dc55b4cb9136aa03dccfec38c9093bdf694b270cc8c804b`, 724 x 572, 46 270 octets).
C'est ce contrôle qui permet d'affirmer que le jar et la version ci-dessus sont bien ceux
qui ont servi.

## Mermaid (`.mermaid`)

```bash
npx --offline @mermaid-js/mermaid-cli@11.16.0 -i <figure>.mermaid -o <figure>.png
```

Version épinglée : une version différente change la mise en page et rend le diff du PNG
illisible.

## Figures figées

`charte-graphique.puml`, `chaine-tracabilite.puml`, `dispositif-veille.puml` et
`gantt-reel.mermaid` datent tous du **05/08/2026**, deux jours avant le rendu du dossier
du 07/08/2026, et n'ont plus bougé depuis. **Ils appartiennent au dossier rendu et ne
doivent pas être modifiés.**

Lorsque l'application évolue et rend une figure obsolète, la règle est donc de **créer une
version datée à côté**, sans toucher à l'originale, comme le fait
`charte-graphique-2026-08-27.puml`. Les deux coexistent, et l'écart entre elles est
documenté dans l'en-tête de la version datée.

## Contrôle de lisibilité

Une figure qui documente des couleurs doit elle-même être lisible. Les étiquettes de
`charte-graphique-2026-08-27.puml` ont été mesurées une à une contre leur fond, et portées
en blanc là où le texte sombre par défaut passait sous 4,5 pour 1. Le détail figure dans
l'en-tête de la source.
