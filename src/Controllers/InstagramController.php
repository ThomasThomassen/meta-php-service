<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\InstagramService;
use App\Support\Response;

class InstagramController
{
    public function getHashtagMedia(): string
    {
        $tag = trim((string) ($_GET['tag'] ?? ''));
        $limit = (int) ($_GET['limit'] ?? 12);
        $type = $_GET['type'] ?? 'recent'; // 'recent' or 'top'
        $fields = $_GET['fields'] ?? null; // optional comma-separated fields override

        if ($tag === '') {
            return Response::json(['error' => 'missing_tag', 'message' => 'Query param "tag" is required.'], 400);
        }
        if (!in_array($type, ['recent', 'top'], true)) {
            return Response::json(['error' => 'invalid_type', 'message' => 'Type must be recent or top.'], 400);
        }

        $service = new InstagramService();
        try {
            $data = $service->getHashtagMedia($tag, $type, $limit, $fields);
            return Response::json(['tag' => $tag, 'type' => $type, 'count' => count($data), 'data' => $data]);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'instagram_error'], 502);
        }
    }

    public function getSelfMedia(): string
    {
        $limit = (int) ($_GET['limit'] ?? 12);
        $fields = $_GET['fields'] ?? null;
        $service = new InstagramService();
        try {
            $data = $service->getUserMedia($limit, $fields);
            return Response::json(['scope' => 'self', 'count' => count($data), 'data' => $data]);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'instagram_error'], 502);
        }
    }

    public function getTaggedMedia(): string
    {
        $limit = (int) ($_GET['limit'] ?? 12);
        $fields = $_GET['fields'] ?? null;
        $service = new InstagramService();
        try {
            $data = $service->getUserTaggedMedia($limit, $fields);
            return Response::json(['scope' => 'tags', 'count' => count($data), 'data' => $data]);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'instagram_error'], 502);
        }
    }

    public function getMergedMedia(): string
    {
        $limit = (int) ($_GET['limit'] ?? 12);
        $fields = $_GET['fields'] ?? null;
        $service = new InstagramService();
        try {
            $data = $service->getMergedSelfAndTagged($limit, $fields);
            return Response::json(['scope' => 'merged', 'count' => count($data), 'data' => $data]);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'instagram_error'], 502);
        }
    }
}
