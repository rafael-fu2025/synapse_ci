<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Guidance\QuestionBankService;
use RuntimeException;
use Throwable;

final class QuestionBankController extends BaseController
{
    public function index()
    {
        return $this->respond(fn () => view('question_bank/index', (new QuestionBankService())->listing($this->request->getGet())
            + ['title' => 'Guidance Question Bank', 'baseUrl' => site_url('guidance/question-bank')]));
    }

    public function catalog()
    {
        return $this->respond(fn () => $this->response->setJSON(['success' => true] + (new QuestionBankService())->listing($this->request->getGet(), true)));
    }

    public function create()
    {
        return $this->respond(function () { (new QuestionBankService())->actor(); return $this->editor(); });
    }

    public function edit(int $id)
    {
        return $this->respond(function () use ($id) {
            $entry = (new QuestionBankService())->get($id);
            if ($entry['status'] !== 'active') throw new RuntimeException('Archived questions cannot be edited.', 409);
            return $this->editor($entry);
        });
    }

    public function show(int $id)
    {
        return $this->respond(function () use ($id) {
            $service = new QuestionBankService();
            return view('question_bank/show', $service->history($id) + [
                'selected' => $service->get($id, $this->request->getGet('revision')),
                'title' => 'Question Revision History', 'baseUrl' => site_url('guidance/question-bank')]);
        });
    }

    public function store()
    {
        return $this->respond(function () {
            (new QuestionBankService())->create($this->input());
            return $this->success('Question added to the bank.');
        });
    }

    public function update(int $id)
    {
        return $this->respond(function () use ($id) {
            (new QuestionBankService())->update($id, $this->input());
            return $this->success('New question revision saved. Existing survey copies are unchanged.');
        });
    }

    public function archive(int $id)
    {
        return $this->respond(function () use ($id) {
            (new QuestionBankService())->archive($id, $this->request->getPost('version'));
            return $this->success('Question archived. Existing survey copies and revision history remain available.');
        });
    }

    private function editor(?array $entry = null): string
    {
        $base = site_url('guidance/question-bank');
        return view('question_bank/form', ['entry' => $entry, 'title' => $entry === null ? 'Add Bank Question' : 'Revise Bank Question',
            'cancelUrl' => $base, 'formModalPage' => true, 'formAction' => $base . ($entry === null ? '/store' : '/' . $entry['id'] . '/update')]);
    }

    private function input(): array
    {
        $input = $this->request->getPost();
        unset($input[csrf_token()]);
        return $input;
    }

    private function success(string $message)
    {
        session()->setFlashdata('success', $message);
        if ($this->json()) return $this->response->setJSON(['success' => true, 'message' => $message,
            'redirect' => site_url('guidance/question-bank'), 'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()]]);
        return redirect()->to(site_url('guidance/question-bank'))->setHeader('Cache-Control', 'private, no-store');
    }

    private function json(): bool
    {
        return $this->request->isAJAX() || str_contains(strtolower($this->request->getHeaderLine('Accept')), 'json');
    }

    private function respond(callable $operation)
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');
        try { return $operation(); }
        catch (Throwable $e) {
            $status = $e instanceof RuntimeException && in_array($e->getCode(), [401, 403, 404, 409, 422], true) ? $e->getCode() : 503;
            if ($status === 503) log_message('error', 'Question bank operation failed: {exception}', ['exception' => $e]);
            $message = $status === 503 ? 'The question bank is temporarily unavailable. Contact the administrator.' : $e->getMessage();
            $this->response->setStatusCode($status);
            if ($this->json()) return $this->response->setJSON(['success' => false, 'message' => $message,
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()]]);
            return $this->response->setContentType('text/plain')->setBody($message);
        }
    }
}
