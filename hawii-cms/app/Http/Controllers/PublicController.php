<?php

namespace App\Http\Controllers;

use App\Models\CaseStudy;
use App\Models\ContactSubmission;
use App\Models\Industry;
use App\Models\Page;
use App\Models\Post;
use App\Models\Service;
use App\Models\Stat;
use App\Models\Testimonial;
use Illuminate\Http\Request;

class PublicController extends Controller
{
    public function home()
    {
        return view('public.home', [
            'stats' => Stat::published()->orderBy('sort')->get(),
            'itServices' => Service::published()->where('pillar', 'it')->orderBy('sort')->get(),
            'aiServices' => Service::published()->where('pillar', 'ai')->orderBy('sort')->get(),
            'industries' => Industry::published()->orderBy('sort')->get(),
            'testimonials' => Testimonial::published()->orderBy('sort')->get(),
        ]);
    }

    public function about()
    {
        return view('public.page', ['page' => Page::published()->where('slug', 'about')->firstOrFail()]);
    }

    public function services()
    {
        return view('public.services', [
            'itServices' => Service::published()->where('pillar', 'it')->orderBy('sort')->get(),
            'aiServices' => Service::published()->where('pillar', 'ai')->orderBy('sort')->get(),
        ]);
    }

    public function serviceShow(Service $service)
    {
        abort_unless($service->is_published, 404);

        return view('public.service-show', ['service' => $service]);
    }

    public function industries()
    {
        return view('public.industries', [
            'industries' => Industry::published()->orderBy('sort')->get(),
        ]);
    }

    public function caseStudies()
    {
        return view('public.cases', [
            'cases' => CaseStudy::published()->orderBy('sort')->get(),
        ]);
    }

    public function caseStudyShow(CaseStudy $caseStudy)
    {
        abort_unless($caseStudy->is_published, 404);

        return view('public.case-show', ['case' => $caseStudy]);
    }

    public function blog()
    {
        return view('public.blog', [
            'posts' => Post::published()->latest('published_at')->paginate(9),
        ]);
    }

    public function postShow(Post $post)
    {
        abort_unless($post->is_published, 404);

        return view('public.post-show', ['post' => $post]);
    }

    public function contact()
    {
        return view('public.contact');
    }

    public function contactStore(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160'],
            'interest' => ['nullable', 'string', 'max:60'],
            'message' => ['nullable', 'string', 'max:5000'],
        ]);

        $data['locale'] = app()->getLocale();
        ContactSubmission::create($data);

        return redirect()->route('contact')->with('sent', true);
    }

    public function page(Page $page)
    {
        abort_unless($page->is_published, 404);

        return view('public.page', ['page' => $page]);
    }
}
