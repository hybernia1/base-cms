<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\ContentType;
use App\Service\Flash;
use App\Service\Setting;
use RedBeanPHP\R as R;

class CustomizerController extends BaseAdminController
{
    public function index(): void
    {
        Auth::requirePanelAccess();

        $this->render('front/customizer.twig', [
            'current_menu' => 'customizer',
            'values' => $this->currentValues(),
            'homepage_options' => $this->homepageOptions(),
        ]);
    }

    public function update(): void
    {
        Auth::requirePanelAccess();

        $showLogo = isset($_POST['theme_show_logo']) ? '1' : '0';
        $showTitle = isset($_POST['theme_show_title']) ? '1' : '0';
        $logoWidth = max(0, min(600, (int) ($_POST['theme_logo_width'] ?? 0)));
        $homepageId = (int) ($_POST['theme_homepage_id'] ?? 0);
        $footerText = trim((string) ($_POST['theme_footer_text'] ?? ''));

        if ($homepageId > 0) {
            $pageExists = R::findOne('content', ' id = ? AND status = ? AND deleted_at IS NULL ', [$homepageId, 'published']);
            if (!$pageExists) {
                $homepageId = 0;
                Flash::addError('Vybraná úvodní stránka už není dostupná.');
            }
        }

        if ($showLogo !== '1' && $showTitle !== '1') {
            $showTitle = '1';
        }

        Setting::set('theme_show_logo', $showLogo);
        Setting::set('theme_show_title', $showTitle);
        Setting::set('theme_logo_width', (string) $logoWidth);
        Setting::set('theme_homepage_id', $homepageId > 0 ? (string) $homepageId : '');
        Setting::set('theme_footer_text', strip_tags($footerText));

        Flash::addSuccess('Nastavení šablony bylo uloženo.');

        header('Location: /admin/customizer');
        exit;
    }

    private function currentValues(): array
    {
        return [
            'theme_show_logo' => Setting::get('theme_show_logo', Setting::DEFAULTS['theme_show_logo']) === '1',
            'theme_show_title' => Setting::get('theme_show_title', Setting::DEFAULTS['theme_show_title']) === '1',
            'theme_logo_width' => (int) Setting::get('theme_logo_width', 0),
            'theme_homepage_id' => (int) Setting::get('theme_homepage_id', 0),
            'theme_footer_text' => (string) Setting::get('theme_footer_text', ''),
        ];
    }

    private function homepageOptions(): array
    {
        $definitions = ContentType::definitions();
        $items = R::findAll('content', ' status = ? AND deleted_at IS NULL ORDER BY title ', ['published']);

        return array_map(function ($item) use ($definitions) {
            $typeDef = $definitions[$item->type] ?? ['menu_label' => $item->type];

            return [
                'id' => (int) $item->id,
                'title' => $item->title,
                'type_label' => $typeDef['menu_label'] ?? ($typeDef['name'] ?? $item->type),
            ];
        }, array_values($items));
    }
}
