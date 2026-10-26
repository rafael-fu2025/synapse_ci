<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Services\Guidance\SurveyService;
use CodeIgniter\HTTP\ResponseInterface;

/** Targeted guidance surveys: availability, question form, identified single submission. */
final class SurveysApiController extends ApiV1Controller
{
    public function index(): ResponseInterface
    {
        return $this->run(function (): ResponseInterface {
            $query = [];
            $status = $this->request->getGet('status');
            if (is_string($status) && in_array(strtolower(trim($status)), ['all', 'available', 'submitted', 'closed'], true)) {
                $query['status'] = strtolower(trim($status));
            }
            $search = $this->request->getGet('q');
            if (is_string($search) && mb_strlen(trim($search)) > 0 && mb_strlen($search) <= 100) {
                $query['q'] = trim($search);
            }
            $result = (new SurveyService())->available($query);
            return $this->ok([
                'data' => $result['items'],
                'meta' => $result['pagination'],
                'filters' => $result['filters'],
                'counts' => $result['counts'],
                'has_profile' => $result['hasProfile'],
            ]);
        });
    }

    public function show(int $id): ResponseInterface
    {
        return $this->run(function () use ($id): ResponseInterface {
            return $this->ok(['data' => (new SurveyService())->form($id)]);
        });
    }

    public function submit(int $id): ResponseInterface
    {
        return $this->run(function () use ($id): ResponseInterface {
            $payload = $this->jsonBody();
            $answers = $payload['answers'] ?? null;
            if (!is_array($answers)) {
                return $this->fail(422, 'validation_failed', 'Survey answers are required.',
                    ['answers' => ['Submit your answers before sending.']]);
            }
            $responseId = (new SurveyService())->submit($id, $answers);
            return $this->ok(['data' => ['response_id' => $responseId]], 201);
        });
    }
}
