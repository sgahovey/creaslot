<?php

declare(strict_types=1);

namespace App\Tests\Accessibilite;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Garde-fou de contraste sur la charte graphique (RGAA 3.2, WCAG 1.4.3 et 1.4.11).
 *
 * Ce test ne relit pas une liste de valeurs figées : il **extrait les jetons de
 * `public/css/creaslot.css`** puis recalcule le rapport de contraste de chaque
 * couple réellement appliqué dans l'interface. Modifier une couleur de la charte
 * sans vérifier son contraste fait donc échouer la suite, ce qui est exactement
 * l'objet de DT-45 : sans ce point de contrôle, rien n'empêche la régression.
 *
 * La formule est celle du WCAG 2.1 : linéarisation sRGB au seuil 0,04045, exposant
 * 2,4, luminance relative pondérée 0,2126 / 0,7152 / 0,0722, puis rapport
 * (L_clair + 0,05) / (L_sombre + 0,05). Elle est elle-même vérifiée sur deux
 * paires de référence connues avant tout usage (cf. test_la_formule_est_juste).
 *
 * ─ Seuils ─────────────────────────────────────────────────────────────────
 * 4,5 pour du texte normal, 3,0 pour du texte large et pour les composants
 * d'interface dont la limite porte l'information.
 *
 * ─ Exclusions assumées ────────────────────────────────────────────────────
 * Ne figurent pas ici, et c'est délibéré : les pastilles de couleur de type de
 * rendez-vous et la bordure de carte, toutes décoratives (les pastilles portent
 * `aria-hidden` et sont doublées du libellé en clair, la carte est déjà
 * distinguée par son fond) ; le jeton `--cs-text-disabled`, déclaré mais
 * appliqué nulle part.
 */
final class ContrasteChartTest extends TestCase
{
    private const CHEMIN_CHARTE = __DIR__ . '/../../public/css/creaslot.css';

    private const TEXTE_NORMAL = 4.5;

    private const COMPOSANT = 3.0;

    /** Nom conventionnel : cette couleur n'est pas un jeton, elle vit dans une règle. */
    private const TEXTE_EVENEMENT = 'texte-evenement';

    /** @var array<string, string>|null */
    private static ?array $jetons = null;

    public function test_la_formule_est_juste(): void
    {
        $this->assertEqualsWithDelta(21.0, $this->contraste('#000000', '#FFFFFF'), 0.01);
        $this->assertEqualsWithDelta(4.54, $this->contraste('#767676', '#FFFFFF'), 0.01);
    }

    public function test_tous_les_jetons_attendus_sont_declares(): void
    {
        $attendus = [
            'cs-text-primary', 'cs-text-secondary', 'cs-bg-page', 'cs-bg-card',
            'cs-blue-primary', 'cs-blue-secondary', 'cs-warning', 'cs-info',
            'cs-border-input', 'bs-link-color',
        ];

        foreach ($attendus as $jeton) {
            $this->assertArrayHasKey($jeton, self::jetons(), sprintf('Jeton "%s" absent de la charte.', $jeton));
        }
    }

    /**
     * @param string $premierPlan Jeton `--cs-*` / `--bs-*`, ou code hexadécimal littéral
     * @param string $fond        Idem
     */
    #[DataProvider('couplesOpaques')]
    public function test_le_couple_atteint_son_seuil(
        string $element,
        string $premierPlan,
        string $fond,
        float $seuil,
    ): void {
        $mesure = $this->contraste($this->resout($premierPlan), $this->resout($fond));

        $this->assertGreaterThanOrEqual(
            $seuil,
            $mesure,
            sprintf(
                '%s : contraste de %.2f pour un seuil de %.1f (%s sur %s).',
                $element,
                $mesure,
                $seuil,
                $this->resout($premierPlan),
                $this->resout($fond),
            ),
        );
    }

    /**
     * @return array<string, array{string, string, string, float}>
     */
    public static function couplesOpaques(): array
    {
        return [
            'corps de texte sur page'    => ['Corps de texte sur page',      'cs-text-primary',   'cs-bg-page',   self::TEXTE_NORMAL],
            'corps de texte sur carte'   => ['Corps de texte sur carte',     'cs-text-primary',   'cs-bg-card',   self::TEXTE_NORMAL],
            'texte secondaire sur page'  => ['Texte secondaire sur page',    'cs-text-secondary', 'cs-bg-page',   self::TEXTE_NORMAL],
            'texte secondaire sur carte' => ['Texte secondaire sur carte',   'cs-text-secondary', 'cs-bg-card',   self::TEXTE_NORMAL],
            'lien sur page'              => ['Lien sur page',                'bs-link-color',     'cs-bg-page',   self::TEXTE_NORMAL],
            'lien sur carte'             => ['Lien sur carte',               'bs-link-color',     'cs-bg-card',   self::TEXTE_NORMAL],
            'bouton primaire'            => ['Bouton primaire',              'cs-text-light',     'cs-blue-primary',   self::TEXTE_NORMAL],
            'bouton primaire au focus'   => ['Bouton primaire au focus',     'cs-text-light',     'cs-blue-secondary', self::TEXTE_NORMAL],
            'bouton secondaire sur page' => ['Bouton secondaire sur page',   'cs-blue-primary',   'cs-bg-page',   self::TEXTE_NORMAL],
            'marque navbar'              => ['Marque navbar',                'cs-text-light',     'cs-blue-primary',   self::TEXTE_NORMAL],
            'marque navbar survolee'     => ['Marque navbar survolée',       'cs-blue-light',     'cs-blue-primary',   self::TEXTE_NORMAL],
            'avatar'                     => ['Avatar',                       'cs-text-light',     'cs-blue-secondary', self::TEXTE_NORMAL],
            'bandeau preproduction'      => ['Bandeau de préproduction',     'cs-text-light',     'cs-warning',   self::TEXTE_NORMAL],
            'bandeau developpement'      => ['Bandeau de développement',     'cs-text-light',     'cs-info',      self::TEXTE_NORMAL],
            'evenement presentiel'       => ['Événement présentiel',         'texte-evenement',   'cs-green-presentiel', self::TEXTE_NORMAL],
            'evenement visio'            => ['Événement visio',              'texte-evenement',   'cs-orange-visio',     self::TEXTE_NORMAL],
            'evenement telephone'        => ['Événement téléphone',          'texte-evenement',   'cs-blue-telephone',   self::TEXTE_NORMAL],
            'bordure de champ'           => ['Bordure de champ de saisie',   'cs-border-input',   'cs-bg-card',   self::COMPOSANT],
            'code d erreur'              => ["Code d'erreur, 96px gras",     'cs-blue-primary',   'cs-bg-page',   self::COMPOSANT],
        ];
    }

    /**
     * Couples dont l'une des couleurs est semi-transparente : elle est aplatie
     * sur son fond avant mesure, comme le fait le navigateur.
     */
    #[DataProvider('couplesSemiTransparents')]
    public function test_le_couple_semi_transparent_atteint_son_seuil(
        string $element,
        string $premierPlan,
        float $opacite,
        string $fond,
        float $seuil,
    ): void {
        $aplati = $this->aplatit($this->resout($premierPlan), $opacite, $this->resout($fond));
        $mesure = $this->contrasteRvb($aplati, $this->versRvb($this->resout($fond)));

        $this->assertGreaterThanOrEqual(
            $seuil,
            $mesure,
            sprintf('%s : contraste de %.2f pour un seuil de %.1f.', $element, $mesure, $seuil),
        );
    }

    /**
     * @return array<string, array{string, string, float, string, float}>
     */
    public static function couplesSemiTransparents(): array
    {
        return [
            'lien navbar'        => ['Lien de la navbar',      'cs-text-light', 0.80, 'cs-blue-primary', self::TEXTE_NORMAL],
            'texte pied de page' => ['Texte du pied de page',  'cs-text-light', 0.75, 'cs-blue-primary', self::TEXTE_NORMAL],
            'lien pied de page'  => ['Lien du pied de page',   'cs-text-light', 0.65, 'cs-blue-primary', self::TEXTE_NORMAL],
            'copyright'          => ['Mention de copyright',   'cs-text-light', 0.63, 'cs-blue-primary', self::TEXTE_NORMAL],
        ];
    }

    /**
     * Les badges de type portent un texte opaque sur un voile de la couleur du
     * type, posé à 12 pour cent sur le fond de carte. Le fond est donc calculé,
     * jamais recopié : c'est ce qui rend le test sensible à un changement de
     * couleur de type.
     */
    #[DataProvider('badgesDeType')]
    public function test_le_badge_de_type_atteint_son_seuil(
        string $element,
        string $texte,
        string $couleurType,
    ): void {
        $fond = $this->aplatit($this->resout($couleurType), 0.12, $this->resout('cs-bg-card'));
        $mesure = $this->contrasteRvb($this->versRvb($texte), $fond);

        $this->assertGreaterThanOrEqual(
            self::TEXTE_NORMAL,
            $mesure,
            sprintf('%s : contraste de %.2f pour un seuil de %.1f.', $element, $mesure, self::TEXTE_NORMAL),
        );
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function badgesDeType(): array
    {
        return [
            'presentiel' => ['Badge présentiel', '#1A6E2E', 'cs-green-presentiel'],
            'visio'      => ['Badge visio',      '#A85200', 'cs-orange-visio'],
            'telephone'  => ['Badge téléphone',  '#004A99', 'cs-blue-telephone'],
        ];
    }

    /**
     * Les créneaux passés sont atténués : l'opacité s'applique au texte comme au
     * fond, et les deux sont donc aplatis sur le blanc de la carte.
     */
    #[DataProvider('creneauxPasses')]
    public function test_le_creneau_passe_reste_lisible(string $element, string $couleurType): void
    {
        $opacite = $this->opaciteDesCreneauxPasses();

        $texte = $this->aplatit($this->resout('texte-evenement'), $opacite, '#FFFFFF');
        $fond = $this->aplatit($this->resout($couleurType), $opacite, '#FFFFFF');

        $mesure = $this->contrasteRvb($texte, $fond);

        $this->assertGreaterThanOrEqual(
            self::TEXTE_NORMAL,
            $mesure,
            sprintf('%s atténué à %.2f : contraste de %.2f.', $element, $opacite, $mesure),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function creneauxPasses(): array
    {
        return [
            'presentiel' => ['Créneau passé présentiel', 'cs-green-presentiel'],
            'visio'      => ['Créneau passé visio',      'cs-orange-visio'],
            'telephone'  => ['Créneau passé téléphone',  'cs-blue-telephone'],
        ];
    }

    // ─ Calcul ────────────────────────────────────────────────────────────────

    private function contraste(string $premierPlan, string $fond): float
    {
        return $this->contrasteRvb($this->versRvb($premierPlan), $this->versRvb($fond));
    }

    /**
     * @param array{float, float, float} $premierPlan
     * @param array{float, float, float} $fond
     */
    private function contrasteRvb(array $premierPlan, array $fond): float
    {
        $clair = $this->luminance($premierPlan);
        $sombre = $this->luminance($fond);

        if ($clair < $sombre) {
            [$clair, $sombre] = [$sombre, $clair];
        }

        return ($clair + 0.05) / ($sombre + 0.05);
    }

    /**
     * @param array{float, float, float} $rvb
     */
    private function luminance(array $rvb): float
    {
        $lineaire = array_map(
            static function (float $composante): float {
                $c = $composante / 255;

                return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            },
            $rvb,
        );

        return 0.2126 * $lineaire[0] + 0.7152 * $lineaire[1] + 0.0722 * $lineaire[2];
    }

    /**
     * @return array{float, float, float}
     */
    private function aplatit(string $premierPlan, float $opacite, string $fond): array
    {
        $pp = $this->versRvb($premierPlan);
        $fd = $this->versRvb($fond);

        return [
            $opacite * $pp[0] + (1 - $opacite) * $fd[0],
            $opacite * $pp[1] + (1 - $opacite) * $fd[1],
            $opacite * $pp[2] + (1 - $opacite) * $fd[2],
        ];
    }

    /**
     * @return array{float, float, float}
     */
    private function versRvb(string $hexadecimal): array
    {
        $hexadecimal = ltrim($hexadecimal, '#');

        return [
            (float) hexdec(substr($hexadecimal, 0, 2)),
            (float) hexdec(substr($hexadecimal, 2, 2)),
            (float) hexdec(substr($hexadecimal, 4, 2)),
        ];
    }

    // ─ Lecture de la charte ──────────────────────────────────────────────────

    /**
     * Résout un jeton de la charte, ou renvoie tel quel un code hexadécimal.
     */
    private function resout(string $valeur): string
    {
        if (str_starts_with($valeur, '#')) {
            return $valeur;
        }

        if (self::TEXTE_EVENEMENT === $valeur) {
            return $this->couleurDuTexteDesEvenements();
        }

        $jetons = self::jetons();

        $this->assertArrayHasKey($valeur, $jetons, sprintf('Jeton "%s" introuvable dans la charte.', $valeur));

        return $jetons[$valeur];
    }

    /**
     * Couleur du texte des évènements du calendrier, lue dans sa règle.
     *
     * Elle n'est pas un jeton de `:root` : c'est une valeur littérale, et c'est
     * précisément pour cela qu'elle doit être relue ici. Sa valeur et l'opacité
     * d'atténuation se tiennent l'une l'autre, la marge sur le bleu téléphone
     * étant de 0,01 (DT-45).
     */
    private function couleurDuTexteDesEvenements(): string
    {
        $trouve = preg_match(
            '/cs-fc-creneau\s+\.fc-event-time\s*\{.*?color:\s*(#[0-9A-Fa-f]{6})/s',
            self::charte(),
            $capture,
        );

        $this->assertSame(1, $trouve, "La couleur du texte des évènements est introuvable dans la charte.");

        return strtoupper($capture[1]);
    }

    private function opaciteDesCreneauxPasses(): float
    {
        $charte = self::charte();

        $trouve = preg_match('/fc-event-past\s*\{[^}]*?opacity:\s*([0-9.]+)/s', $charte, $capture);

        $this->assertSame(1, $trouve, "L'opacité des créneaux passés est introuvable dans la charte.");

        return (float) $capture[1];
    }

    /**
     * @return array<string, string>
     */
    private static function jetons(): array
    {
        if (null !== self::$jetons) {
            return self::$jetons;
        }

        preg_match_all(
            '/--((?:cs|bs)-[a-z0-9-]+)\s*:\s*(#[0-9A-Fa-f]{6})\s*;/',
            self::charte(),
            $captures,
            \PREG_SET_ORDER,
        );

        $jetons = [];
        foreach ($captures as $capture) {
            $jetons[$capture[1]] = strtoupper($capture[2]);
        }

        return self::$jetons = $jetons;
    }

    private static function charte(): string
    {
        $contenu = file_get_contents(self::CHEMIN_CHARTE);

        if (false === $contenu) {
            throw new \RuntimeException('Charte graphique illisible : ' . self::CHEMIN_CHARTE);
        }

        return $contenu;
    }
}
