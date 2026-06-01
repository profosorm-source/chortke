<?php
namespace App\Controllers;

use App\Models\Page;
use App\Controllers\BaseController;
use Core\Response;
/**
 * Page Controller
 * 
 * مدیریت صفحات استاتیک
 */
class PageController extends BaseController
{
   
	 
	private Page $pageModel;

    public function __construct(\App\Models\Page $pageModel, ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
        $this->pageModel = $pageModel;
    }

	/**
     * نمایش صفحه
     */
    public function show(string $slug)
    {
        if (!preg_match('/^[a-z0-9\-]+$/i', $slug)) {
            return view('errors/404');
        }
        
        $page = $this->pageModel->findBySlug($slug);
        
        if (!$page) {
            return view('errors/404');
        }
        
        return view('pages/show', [
            'page' => $page
        ]);
    }

    /**
     * درباره ما
     */
    public function about()
    {
        return $this->response->view('pages.about', [
            'title' => 'درباره ما'
        ]);
    }

    /**
     * تماس با ما
     */
    public function contact()
    {
        return $this->response->view('pages.contact', [
            'title' => 'تماس با ما'
        ]);
    }

    /**
     * قوانین و مقررات
     */
    public function terms()
    {
        return $this->response->view('pages.terms', [
            'title' => 'قوانین و مقررات'
        ]);
    }

    /**
     * حریم خصوصی
     */
    public function privacy()
    {
        return $this->response->view('pages.privacy', [
            'title' => 'سیاست حفظ حریم خصوصی'
        ]);
    }

    /**
     * راهنما
     */
    public function help()
    {
        return $this->response->view('pages.help', [
            'title' => 'راهنمای استفاده'
        ]);
    }
}