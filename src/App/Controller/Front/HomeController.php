<?php
namespace App\Controller\Front;

use RedBeanPHP\R as R;

class HomeController
{
    private $twig;

    public function __construct()
    {
        $this->twig = $GLOBALS['app']['twig'];
    }

    public function index()
    {
        $posts = R::findAll('content', ' type = ? ORDER BY created_at DESC ', ['post']);

        echo $this->twig->render('front/home.twig', [
            'posts' => $posts,
        ]);
    }
}
