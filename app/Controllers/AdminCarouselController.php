<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\CarouselSlideModel;
use App\Services\AuthenticatedUser;
use App\Services\Carousel\CarouselAccessPolicy;
use App\Services\Carousel\CarouselService;
use CodeIgniter\HTTP\ResponseInterface;
use Exception;
use RuntimeException;
use Throwable;

/**
 * Admin-only management of the login screen photo carousel.
 *
 * Slides are multi-tenant scoped. Images are uploaded into the managed private
 * store. Each slide carries an optional dark-mode variant and a fit mode controlling whether the image is shown
 * crisp (cover) or letterboxed over a blurred copy of itself (contain_blur).
 *
 * The permanent default image is held in the managed store and is immutable:
 * it may not be edited by anyone, and only the platform owner may delete or
 * hide the slide that carries it.
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
        $slides = $this->slideModel->getAllSlides();
        $protected = [];
        foreach ($slides as $slide) {
            $protected[(int) $slide['id']] = CarouselAccessPolicy::usesDefaultAsset($slide);
        }

        return view('admin/carousel/index', [
            'title'       => 'Login Carousel',
            'formModalPage' => false,
            'pageTitle'   => 'Login Carousel Slides',
            'slides'      => $slides,
            'slide'       => null,
            'protectedSlides' => $protected,
            'isSuperadmin' => $this->isSuperadmin(),
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
        ];

        $lightFile = $this->request->getFile('light_file');
        $darkFile  = $this->request->getFile('dark_file');

        if ($lightFile === null || $lightFile->getError() === UPLOAD_ERR_NO_FILE) {
            return $this->respond('A light mode image upload is required.', false);
        }

        try {
            $this->carousel->createSlide($data, $lightFile, $darkFile);

            return $this->respond('Carousel slide created successfully.', true, 422, $this->regionalRefresh());
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
            'lockedImage' => CarouselAccessPolicy::usesDefaultAsset($slide),
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

        $actor = $this->actor();
        if (!CarouselAccessPolicy::canEdit($actor, $slide)) {
            return $this->respond(CarouselAccessPolicy::EDIT_DENIED, false, 403);
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
                // Explicit removal wins over a replacement upload.
                $data['dark_image'] = null;
                $darkFile = null;
            }
            $this->carousel->updateSlide($id, $data, $lightFile, $darkFile, $actor);

            return $this->respond('Carousel slide updated successfully.', true, 422, $this->regionalRefresh());
        } catch (RuntimeException $e) {
            return $this->respond($e->getMessage(), false, $e->getCode() === 403 ? 403 : 422);
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
            $slide = $this->carousel->toggleActive($id, $this->actor());
            $state = ((int) $slide['is_active'] === 1) ? 'Slide is now visible.' : 'Slide is now hidden.';
            return $this->respond($state, true, 422, $this->regionalRefresh());
        } catch (RuntimeException $e) {
            return $this->respond($e->getMessage(), false, $e->getCode() === 403 ? 403 : 422);
        } catch (Throwable $e) {
            return $this->respond($e->getMessage(), false);
        }
    }

    /**
     * Delete a slide and its uploaded files.
     */
    public function delete(int $id)
    {
        $slide = $this->slideModel->scoped()->find($id);
        if ($slide === null) {
            return $this->respond('Slide not found.', false);
        }

        $actor = $this->actor();
        if (!CarouselAccessPolicy::canDelete($actor, $slide)) {
            return $this->respond(CarouselAccessPolicy::DELETE_DENIED, false, 403);
        }

        try {
            if ($this->carousel->deleteSlide($id, $actor)) {
                return $this->respond('Slide deleted.', true, 422, $this->regionalRefresh());
            }
        } catch (RuntimeException $e) {
            return $this->respond($e->getMessage(), false, $e->getCode() === 403 ? 403 : 422);
        }

        return $this->respond('Slide not found.', false);
    }

    /**
     * The acting identity, revalidated from the database-backed session.
     *
     * @return array<string, mixed>
     */
    private function actor(): array
    {
        return (new AuthenticatedUser())->current() ?? [];
    }

    private function isSuperadmin(): bool
    {
        return ($this->actor()['role'] ?? '') === 'superadmin';
    }

    /**
     * Persist a new ordering. Expects `order` as a comma-separated list of IDs.
     */
    public function reorder()
    {
        $raw = $this->request->getPost('order');
        if (!is_string($raw)) {
            return $this->respondToReorder('No ordering supplied.', false);
        }
        $ids = array_values(array_filter(
            array_map('intval', array_map('trim', explode(',', $raw))),
            static fn (int $v): bool => $v > 0
        ));

        if (empty($ids)) {
            return $this->respondToReorder('No ordering supplied.', false);
        }

        try {
            // Only reorder slides the current tenant can see.
            $owned = array_column($this->slideModel->getAllSlides(), 'id');
            $ids = array_values(array_intersect($ids, array_map('intval', $owned)));
            if (empty($ids)) {
                return $this->respondToReorder('No ordering supplied.', false);
            }

            $this->carousel->reorderSlides($ids);
        } catch (Throwable $e) {
            log_message('error', 'Unable to reorder carousel slides: {exception}', ['exception' => $e]);
            return $this->respondToReorder('Unable to update slide order. Please try again.', false, 500);
        }

        return $this->respondToReorder('Slide order updated.', true, 422, $this->reorderRefresh());
    }

    private function respondToReorder(string $message, bool $success = true, int $errorStatus = 422, ?array $refresh = null)
    {
        if ($this->request->isAJAX()) {
            // Reordering stays on the current page; do not queue a later flash notice.
            $payload = [
                'success' => $success,
                'message' => $message,
                'csrf'    => ['name' => csrf_token(), 'hash' => csrf_hash()],
            ];
            if ($success && $refresh !== null) { $payload['refresh'] = $refresh; }
            return $this->response->setStatusCode($success ? 200 : $errorStatus)->setJSON($payload);
        }

        return redirect()->to('/admin/carousel')->with($success ? 'success' : 'error', $message);
    }

    /**
     * Returns JSON for XHR uploads, a redirect (with flash) otherwise.
     *
     * Both paths land on the slide manager: saving never navigates away to a
     * separate editor, so the refreshed list confirms what was stored.
     *
     * @return RedirectResponse|ResponseInterface
     */
    private function respond(string $message, bool $success = true, int $errorStatus = 422, ?array $refresh = null)
    {
        if ($this->request->isAJAX()) {
            // Legacy uploads navigate after saving; toast-owned requests refresh in place.
            if ($success && $this->request->getHeaderLine('X-Synapse-Feedback') !== 'toast') {
                session()->setFlashdata('success', $message);
            }

            $payload = [
                'success'  => $success,
                'message'  => $message,
                'redirect' => site_url('/admin/carousel'),
                'csrf'     => ['name' => csrf_token(), 'hash' => csrf_hash()],
            ];
            if ($refresh !== null) { $payload['refresh'] = $refresh; }
            return $this->response
                ->setStatusCode($success ? 200 : $errorStatus)
                ->setJSON($payload);
        }

        $redirect = redirect()->to('/admin/carousel');
        return $this->request->getHeaderLine('X-Synapse-Feedback') === 'toast'
            ? $redirect : $redirect->with($success ? 'success' : 'error', $message);
    }

    private function regionalRefresh(): ?array
    {
        if (!$this->refreshEnabled()) { return null; }

        return $this->refreshRegions('carousel.admin', ['carousel.slides']);
    }

    private function reorderRefresh(): ?array
    {
        if (!$this->refreshEnabled()) { return null; }

        return $this->refreshNone();
    }

    private function refreshEnabled(): bool
    {
        return config(\Config\RefreshRegions::class)->enables('carousel.admin')
            && $this->request->getHeaderLine('X-Synapse-Feedback') === 'toast';
    }
}
