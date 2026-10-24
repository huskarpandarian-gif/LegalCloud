<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PageController extends Controller
{
    public function home(): View
    {
        return $this->page('dashboard');
    }

    public function dashboard(): View
    {
        return $this->page('dashboard');
    }

    public function users(): View
    {
        return $this->page('users');
    }

    public function categories(): View
    {
        return $this->page('categories');
    }

    public function cases(): View
    {
        return $this->page('cases');
    }

    public function documents(): View
    {
        return $this->page('documents');
    }

    public function search(): View
    {
        return $this->page('search');
    }

    public function assistant(): View
    {
        return $this->page('assistant');
    }

    public function ocr(): View
    {
        return $this->page('ocr');
    }

    public function notifications(): View
    {
        return $this->page('notifications');
    }

    public function audit(): View
    {
        return $this->page('audit');
    }

    public function profile(): View
    {
        return $this->page('settings', 'profile');
    }

    public function password(): View
    {
        return $this->page('settings', 'password');
    }

    public function appearance(): View
    {
        return $this->page('settings', 'appearance');
    }

    public function settings(): RedirectResponse
    {
        return redirect('/settings/profile');
    }

    private function page(string $view, string $section = 'profile'): View
    {
        return view('legalcloud', [
            'initialView' => $view,
            'settingsSection' => $section,
        ]);
    }
}
