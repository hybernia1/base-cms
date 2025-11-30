<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\Flash;
use App\Service\Upload;
use RedBeanPHP\R as R;

class MediaController extends BaseAdminController
{
    public function index()
    {
        Auth::requireRole(['admin', 'editor']);
        $items = R::findAll('media', ' ORDER BY created_at DESC LIMIT 200 ');

        $this->render('admin/media/index.twig', [
            'items' => $items,
            'current_menu' => 'media',
        ]);
    }

    public function upload()
    {
        Auth::requireRole(['admin', 'editor']);

        if (!isset($_FILES['file'])) {
            Flash::addError('Nebyl vybrán žádný soubor.');
            header('Location: /admin/media');
            exit;
        }

        [$media, $error] = Upload::handle($_FILES['file']);
        if ($error) {
            Flash::addError($error);
            header('Location: /admin/media');
            exit;
        }

        $media->alt = trim($_POST['alt'] ?? '');
        R::store($media);

        Flash::addSuccess('Soubor byl nahrán.');
        header('Location: /admin/media');
        exit;
    }
}
