<?php
namespace App\Controller\Front;

use RedBeanPHP\R as R;

class HomeController extends BaseFrontController
{

    public function index()
    {
        $posts = R::findAll('content', ' type = ? AND status = ? ORDER BY created_at DESC ', ['post', 'published']);

        $this->render('front/home.twig', [
            'posts' => $posts,
        ]);
    }
}
