<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\CarouselSlideModel;
use App\Services\Carousel\CarouselService;
use CodeIgniter\HTTP\ResponseInterface;
use Exception;
use Throwable;

/**
 * Admin-only management of the login screen photo carousel.
 *
 * Slides are multi-tenant scoped. Images may be uploaded (no artificial
 * size cap) or referenced by relative path. Each slide carries an optional
 * dark-mode variant and a fit mode controlling whether the image is shown
 * crisp (cover) or letterboxed over a blurred copy of itself (contain_blur).
 */
final class AdminCarouselController extends BaseController
{
    private CarouselService $carousel;
    private CarouselSlideModel $slideModel;

    public function __construct()
    {
        $this->carousel   = new CarouselService();
        $this->slideModel = new CarouselSlideModel();
    }

    /**
     * Management screen: list all slides (active + inactive) with upload form.
     */
    public function index()
    {
        return view('admin/carousel/index', [
            'title'       => 'Login Carousel',
            'formModalPage' => false,
            'pageTitle'   => 'Login Carousel Slides',
            'slides'      => $this->slideModel->getAllSlides(),
            'slide'       => null,
            'breadcrumbs' => [
                ['label' => 'Governance', 'url' => site_url('/admin/audit')],
                ['label' => 'Login Carousel', 'url' => ''],
            ],
        ]);
    }

    /**
     * Create a new slide from the management form.
     */
    public function store()
    {
        $data = [
            'title'    => $this->request->getPost('title'),
            'subtitle' => $this->request->getPost('subtitle'),
            'fit_mode' => (string) ($this->request->getPost('fit_mode') ?? 'cover'),
            // A required upload may be omitted only when the user selected a local path.
            'light_image' => $this->request->getPost('light_path'),
        ];

        $lightFile = $this->request->getFile('light_file');
        $darkFile  = $this->request->getFile('dark_file');

        try {
            // Uploads take precedence; the service validates files and local paths.
            $this->applyPathFallbacks($data, $lightFile, $darkFile);
            $this->carousel->createSlide($data, $lightFile, $darkFile);

            return $this->respond('Carousel slide created successfully.');
        } catch (Exception $e) {
            return $this->respond('Unable to save slide: ' . $e->getMessage(), false);
        }
    }

    public function create()
    {
        return $this->renderForm(null, 'Add Carousel Slide', site_url('/admin/carousel'));
    }

    /**
     * Edit form for an existing slide.
     */
    public function edit(int $id)
    {
        $slide = $this->slideModel->scoped()->find($id);
        if ($slide === null) {
            return redirect()->to('/admin/carousel')->with('error', 'Slide not found.');
        }

        return $this->renderForm($slide, 'Edit Carousel Slide', site_url('/admin/carousel'));
    }

    private function renderForm(?array $slide, string $title, string $cancelUrl)
    {
        return view('admin/carousel/' . ($slide === null ? 'create' : 'edit'), [
            'title'       => $title,
            'pageTitle'   => $title,
            'slide'       => $slide,
            'cancelUrl'   => $cancelUrl,
            'formModalPage' => true,
            'breadcrumbs' => [
                ['label' => 'Governance', 'url' => site_url('/admin/audit')],
                ['label' => 'Login Carousel', 'url' => site_url('/admin/carousel')],
                ['label' => $title, 'url' => ''],
            ],
        ]);
    }

    /**
     * Persist edits to an existing slide (optionally replacing images).
     */
    public function update(int $id)
    {
        $slide = $this->slideModel->scoped()->find($id);
        if ($slide === null) {
            return $this->respond('Slide not found.', false);
        }

        $data = [
            'title'       => $this->request->getPost('title'),
            'subtitle'    => $this->request->getPost('subtitle'),
            'fit_mode'    => (string) ($this->request->getPost('fit_mode') ?? 'cover'),
            'light_image' => $slide['light_image'],
            'dark_image'  => $slide['dark_image'],
        ];

        $lightFile = $this->request->getFile('light_file');
        $darkFile  = $this->request->getFile('dark_file');

        try {
            $removeDark = $this->request->getPost('remove_dark') === '1';
            if ($removeDark) {
                // Explicit removal wins over both a replacement upload and a path.
                $data['dark_image'] = null;
                $darkFile = null;
            }
            $this->applyPathFallbacks($data, $lightFile, $darkFile, $removeDark);
            $this->carousel->updateSlide($id, $data, $lightFile, $darkFile);

            return $this->respond('Carousel slide updated successfully.');
        } catch (Exception $e) {
            return $this->respond('Unable to update slide: ' . $e->getMessage(), false);
        }
    }

    /**
     * Activate / deactivate a slide.
     */
    public function toggle(int $id)
    {
        try {
            $slide = $this->carousel->toggleActive($id);
            $state = ((int) $slide['is_active'] === 1) ? 'Slide is now visible.' : 'Slide is now hidden.';
            return $this->respond($state);
        } catch (Throwable $e) {
            return $this->respond($e->getMessage(), false);
        }
    }

    /**
     * Delete a slide and its uploaded files.
     */
    public function delete(int $id)
    {
        if ($this->carousel->deleteSlide($id)) {
            return $this->respond('Slide deleted.');
        }

        return $this->respond('Slide not found.', false);
    }

    /**
     * Persist a new ordering. Expects `order` as a comma-separated list of IDs.
     */
    public function reorder()
    {
        $raw = (string) $this->request->getPost('order');
        $ids = array_values(array_filter(
            array_map('intval', array_map('trim', explode(',', $raw))),
            static fn (int $v): bool => $v > 0
        ));

        if (empty($ids)) {
            return redirect()->to('/admin/carousel')->with('error', 'No ordering supplied.');
        }

        // Only reorder slides the current tenant can see.
        $owned = array_column($this->slideModel->getAllSlides(), 'id');
        $ids = array_values(array_intersect($ids, array_map('intval', $owned)));

        $this->carousel->reorderSlides($ids);

        return redirect()->to('/admin/carousel')->with('success', 'Slide order updated.');
    }

    /**
     * Normalize separators, but reject unsafe paths rather than repairing traversal.
     * The service also checks existence, realpath containment and raster MIME type.
     */
    private function sanitizePath(mixed $path): string
    {
        if (!is_string($path)) {
            throw new \InvalidArgumentException('Carousel image path must be text.');
        }
        $path = str_replace('\\', '/', trim($path));
        if ($path === '') { return ''; }
        if (strlen($path) > 255
            || !preg_match('#\A(assets/img/brand|uploads/carousel)/([A-Za-z0-9_-][A-Za-z0-9._-]*(?:/[A-Za-z0-9_-][A-Za-z0-9._-]*)*)\z#D', $path, $matches)
            || ($matches[1] === 'uploads/carousel' && str_contains($matches[2], '/'))
        ) {
            throw new \InvalidArgumentException('Use a safe relative image path inside assets/img/brand/ or uploads/carousel/.');
        }
        return $path;
    }

    /**
     * Applies library-path fallbacks when no file was uploaded for a slot.
     *
     * @param array<string, mixed> $data
     */
    private function applyPathFallbacks(array &$data, $lightFile, $darkFile, bool $removeDark = false): void
    {
        foreach (['light' => $lightFile, 'dark' => $darkFile] as $slot => $file) {
            // Failed uploads must reach service validation, never silently become path saves.
            if (($slot === 'dark' && $removeDark)
                || ($file !== null && $file->getError() !== UPLOAD_ERR_NO_FILE)) {
                continue;
            }
            $raw = $this->request->getPost($slot . '_path');
            if ($raw === null) { continue; }
            $path = $this->sanitizePath($raw);
            // Blank edit fields retain existing images; only remove_dark clears the variant.
            if ($path !== '') { $data[$slot . '_image'] = $path; }
        }
    }

    /**
     * Returns JSON for XHR uploads, a redirect (with flash) otherwise.
     *
     * Both paths land on the slide manager: saving never navigates away to a
     * separate editor, so the refreshed list confirms what was stored.
     *
     * @return RedirectResponse|ResponseInterface
     */
    private function respond(string $message, bool $success = true)
    {
        if ($this->request->isAJAX()) {
            // The browser navigates separately once the XHR resolves, so carry
            // the confirmation as flash data for that following request.
            if ($success) {
                session()->setFlashdata('success', $message);
            }

            return $this->response
                ->setStatusCode($success ? 200 : 422)
                ->setJSON([
                    'success'  => $success,
                    'message'  => $message,
                    'redirect' => site_url('/admin/carousel'),
                    'csrf'     => ['name' => csrf_token(), 'hash' => csrf_hash()],
                ]);
        }

        return redirect()->to('/admin/carousel')
            ->with($success ? 'success' : 'error', $message);
    }
}
