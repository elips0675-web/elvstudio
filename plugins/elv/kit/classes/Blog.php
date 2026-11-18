<?php namespace Elv\Kit\Classes;

use Elv\Kit\Models\Category;
use Elv\Kit\Models\Post;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Blog — выборки статей для списка, фильтров и детальной страницы.
 */
class Blog
{
    public static function paginate(
        array $filters = [],
        int $perPage = 9,
        string $audience = Post::AUDIENCE_BUSINESS
    ): LengthAwarePaginator {
        $query = Post::query()->published()->with('category')->audience($audience);

        $category = trim((string) ($filters['category'] ?? ''));

        if ($category !== '') {
            $query->whereHas('category', function ($q) use ($category) {
                $q->where('slug', $category);
            });
        }

        $tag = trim((string) ($filters['tag'] ?? ''));

        if ($tag !== '') {
            $query->where('tags', 'like', '%' . $tag . '%');
        }

        $search = trim((string) ($filters['q'] ?? ''));

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $like = '%' . $search . '%';
                $q->where('title', 'like', $like)
                    ->orWhere('excerpt', 'like', $like)
                    ->orWhere('body', 'like', $like);
            });
        }

        return $query->paginate($perPage)->withQueryString();
    }

    public static function find(string $slug): ?Post
    {
        return Post::query()
            ->published()
            ->with('category')
            ->where('slug', $slug)
            ->first();
    }

    public static function start(int $limit = 0): array
    {
        $path = base_path('plugins/elv/kit/config/blog.php');
        $config = is_file($path) ? (array) require $path : [];
        $slugs = array_values(array_filter(array_map('strval', (array) ($config['start'] ?? []))));

        if ($slugs === []) {
            return [];
        }

        $found = Post::query()
            ->published()
            ->with('category')
            ->whereIn('slug', $slugs)
            ->get()
            ->keyBy('slug');

        $posts = [];

        foreach ($slugs as $slug) {
            if ($found->has($slug)) {
                $posts[] = $found->get($slug);
            }
        }

        return $limit > 0 ? array_slice($posts, 0, $limit) : $posts;
    }

    /**
     * total — число опубликованных статей аудитории.
     */
    public static function total(string $audience = Post::AUDIENCE_BUSINESS): int
    {
        return Post::query()->published()->audience($audience)->count();
    }

    /**
     * categories — рубрики с числом опубликованных статей выбранной аудитории.
     */
    public static function categories(string $audience = Post::AUDIENCE_BUSINESS)
    {
        return Category::query()
            ->withCount(['posts' => function ($q) use ($audience) {
                $q->where('is_published', true);

                if ($audience !== '' && $audience !== 'all') {
                    $q->where('audience', $audience);
                }
            }])
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * tags — уникальные теги опубликованных статей.
     *
     * @return string[]
     */
    public static function tags(string $audience = Post::AUDIENCE_BUSINESS): array
    {
        $tags = [];

        foreach (Post::query()->published()->audience($audience)->pluck('tags') as $row) {
            $decoded = is_array($row) ? $row : json_decode((string) $row, true);

            foreach ((array) $decoded as $tag) {
                $tag = trim((string) $tag);

                if ($tag !== '') {
                    $tags[$tag] = ($tags[$tag] ?? 0) + 1;
                }
            }
        }

        arsort($tags);

        return array_keys($tags);
    }

    public static function related(Post $post, int $limit = 3)
    {
        $audience = (string) ($post->audience ?: Post::AUDIENCE_BUSINESS);
        $query = Post::query()
            ->published()
            ->audience($audience)
            ->with('category')
            ->where('id', '!=', $post->id);

        if ($post->category_id) {
            $query->where('category_id', $post->category_id);
        }

        $posts = $query->limit($limit)->get();

        if ($posts->count() < $limit) {
            $posts = Post::query()
                ->published()
                ->audience($audience)
                ->with('category')
                ->where('id', '!=', $post->id)
                ->limit($limit)
                ->get();
        }

        return $posts;
    }
}
