<?php namespace Elv\Kit\Classes;

use Elv\Kit\Models\Category;
use Elv\Kit\Models\Post;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Blog — выборки статей для списка, фильтров и детальной страницы.
 */
class Blog
{
    public static function paginate(array $filters = [], int $perPage = 9): LengthAwarePaginator
    {
        $query = Post::query()->published()->with('category');

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

    /**
     * categories — рубрики с числом опубликованных статей.
     */
    public static function categories()
    {
        return Category::query()
            ->withCount(['posts' => function ($q) {
                $q->where('is_published', true);
            }])
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * tags — уникальные теги опубликованных статей.
     *
     * @return string[]
     */
    public static function tags(): array
    {
        $tags = [];

        foreach (Post::query()->published()->pluck('tags') as $row) {
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
        $query = Post::query()->published()->with('category')->where('id', '!=', $post->id);

        if ($post->category_id) {
            $query->where('category_id', $post->category_id);
        }

        $posts = $query->limit($limit)->get();

        if ($posts->count() < $limit) {
            $posts = Post::query()
                ->published()
                ->with('category')
                ->where('id', '!=', $post->id)
                ->limit($limit)
                ->get();
        }

        return $posts;
    }
}
