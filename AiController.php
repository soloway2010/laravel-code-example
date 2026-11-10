<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backend;

use App\Helpers\PostTokensHelper;
use App\Helpers\StringHelper;
use App\Helpers\WidgetTypeDetector;
use App\Models\Category;
use App\Models\Config\Config;
use App\Models\Post\Post;
use App\Models\Post\PostWidget;
use App\Models\Tag;
use App\Models\UserConfig;
use App\Services\AdminManagement\WidgetService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\AiApiService;
use Illuminate\Support\Facades\Auth;

class AiController extends BaseController
{
    private const PROHIBITED_GROUP_FORMS_FORMAT = <<<'PROMPT'
ФОРМАТ ОТВЕТА (обязательно):
- Только список словоформ, по одной на строку.
- Без пояснений, нумерации, маркеров, кавычек вокруг строк и markdown.
- Без пустых строк и дублей.
- Ничего кроме самих форм входного описания.
PROMPT;

    public function __construct(
        private readonly AiApiService $ai,
    ) {
    }

    public function getResponse(Request $request): JsonResponse
    {
        $configName = $request->post('configName');
        $text = $request->post('text');

        return response()->json($this->getResponseInternal($configName, $text));
    }

    public function getBacksResponse(Request $request): JsonResponse
    {
        $configName = 'AI_PROMPT_BACKS';
        $backIds = $request->post('backIds');

        $posts = Post::query()->whereIn('id', $backIds)->get();
        $posts = $posts->sortBy(fn ($post) => array_search($post->id, $backIds));
        
        $text = '';
        foreach ($posts as $i => $post) {
            $num = $i + 1;

            $text .= "НОВОСТЬ_{$num}:\n";
            $text .= StringHelper::firstParagraph($post->description);
            $text .= "\n\n";
        }

        return response()->json($this->getResponseInternal($configName, $text));
    }

    public function getTagsResponse(Request $request): JsonResponse
    {
        $configName = 'AI_PROMPT_TAGS';
        $text = mb_strtolower(strip_tags($request->post('text')));

        $tags = Tag::all();
        $tagsByName = [];
        foreach ($tags as $tag) {
            $tagsByName[mb_strtolower($tag->name)] = $tag;
        }

        $categories = [];
        $categoryTagsByName = [];
        foreach (
            Category::query()
                ->where('status', true)
                ->whereNotNull('parent_id')
                ->pluck('title')
                ->unique() as $title
        ) {
            $titleLower = mb_strtolower($title);
            if (!isset($tagsByName[$titleLower])) {
                continue;
            }

            $tag = $tagsByName[$titleLower];
            $categories[] = $tag->name;
            $categoryTagsByName[$titleLower] = ['id' => $tag->id, 'text' => $tag->name];
        }

        $matchingTags = [];
        $matchingTagsByName = $categoryTagsByName;
        foreach ($tags as $tag) {
            $tagName = mb_strtolower($tag->name);

            if (preg_match('/' . preg_quote($tagName, '/') . '/ui', $text)) {
                $matchingTags[$tag->id] = $tag->name;
                $matchingTagsByName[$tagName] = ['id' => $tag->id, 'text' => $tag->name];
            }
        }

        $data = 'Текст новости: ' . $text
            . ' Список категорий (раздел — обязательно ставь их первыми в списке тегов): ' . implode(', ', $categories)
            . ' Список тегов: ' . implode(', ', $matchingTags);

        $result = $this->getResponseInternal($configName, $data);
        if (!$result['success']) {
            return response()->json($result);
        }

        $resultTags = [];
        foreach (explode(',', $result['result']) as $tag) {
            $tagName = mb_strtolower(trim($tag));
            if (isset($matchingTagsByName[$tagName])) {
                array_push($resultTags, $matchingTagsByName[$tagName]);
            }
        }

        return response()->json([
            'result' => $resultTags,
            'success' => true
        ]);
    }

    public function getProhibitedGroupFormsResponse(Request $request): JsonResponse
    {
        $text = trim((string) $request->post('text', ''));
        if ($text === '') {
            return response()->json([
                'success' => false,
                'message' => 'Укажите описание группы',
            ]);
        }

        $message = "Входное описание (склоняй только его, ничего другого):\n{$text}";
        $result = $this->getResponseInternal(
            'AI_PROMPT_PROHIBITED_GROUP_FORMS',
            $message,
            self::PROHIBITED_GROUP_FORMS_FORMAT
        );
        if (!$result['success']) {
            return response()->json($result);
        }

        $forms = [];
        foreach (preg_split('/\R+/u', (string) $result['result']) as $line) {
            $line = trim($line);
            $line = preg_replace('/^[\d]+[.)]\s*/u', '', $line) ?? $line;
            $line = preg_replace('/^[-*•]\s*/u', '', $line) ?? $line;
            $line = trim($line, " \t\n\r\0\x0B\"'");

            if ($line === '') {
                continue;
            }

            $forms[$line] = $line;
        }

        return response()->json([
            'success' => true,
            'result' => array_values($forms),
        ]);
    }

    private function getResponseInternal($configName, $text, ?string $promptSuffix = null): array
    {
        try {
            $prompt = UserConfig::searchQuery()->where('param', $configName)->first()?->default;
            if (empty($prompt)) {
                $prompt = UserConfig::getConfigList()[$configName]['default'] ?? null;
            }

            if ($promptSuffix !== null && $prompt !== null) {
                $prompt = rtrim($prompt) . "\n\n" . $promptSuffix;
            }

            return [
                'result' => $this->ai->getResponse($text, $prompt),
                'success' => true
            ];
        } catch (Exception $e) {
            report($e);
            return [
                'message' => $e->getMessage(),
                'success' => false
            ];
        }
    }
}
