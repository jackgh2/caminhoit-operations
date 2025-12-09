<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$user_id = $_SESSION['user']['id'] ?? null;
$user_role = $_SESSION['user']['role'] ?? null;

// Determine visibility based on user authentication
$visibility_conditions = ["'public'"];
if ($user_id) {
    $visibility_conditions[] = "'authenticated'";
    if (in_array($user_role, ['administrator', 'support_consultant', 'accountant'])) {
        $visibility_conditions[] = "'staff_only'";
    }
}
$visibility_clause = "AND a.visibility IN (" . implode(',', $visibility_conditions) . ")";

// Get search parameters
$query = trim($_GET['q'] ?? '');
$category = (int)($_GET['category'] ?? 0);
$sort = $_GET['sort'] ?? 'relevance';
$limit = min(20, max(5, (int)($_GET['limit'] ?? 10)));

$response = [
    'query' => $query,
    'results' => [],
    'total' => 0,
    'suggestions' => []
];

try {
    if (strlen($query) < 2) {
        // Return popular articles for short queries
        $stmt = $pdo->prepare("
            SELECT a.id, a.title, a.slug, a.excerpt, a.view_count, a.published_at,
                   c.name as category_name, c.slug as category_slug, c.color as category_color,
                   u.username as author_name
            FROM kb_articles a
            JOIN kb_categories c ON a.category_id = c.id
            LEFT JOIN users u ON a.author_id = u.id
            WHERE a.status = 'published' $visibility_clause
            ORDER BY a.view_count DESC, a.published_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($results as &$result) {
            $result['excerpt'] = $result['excerpt'] ? substr($result['excerpt'], 0, 150) . '...' : '';
            $result['url'] = '/kb/' . $result['slug'];
            $result['category_url'] = '/kb/category/' . $result['category_slug'];
            $result['formatted_date'] = date('M j, Y', strtotime($result['published_at']));
            $result['tags'] = [];
            $result['view_count'] = (int)$result['view_count'];
            $result['relevance_score'] = 0;
        }

        $response['results'] = $results;
        $response['type'] = 'popular';
        $response['total'] = count($results);
    } else {
        // Enhanced search query
        $like_term = "%$query%";

        $search_sql = "
            SELECT a.id, a.title, a.slug, a.excerpt, a.view_count, a.published_at,
                   c.name as category_name, c.slug as category_slug, c.color as category_color,
                   u.username as author_name,
                   (CASE 
                       WHEN LOWER(a.title) LIKE LOWER(?) THEN 50 ELSE 0 END +
                    CASE 
                       WHEN LOWER(a.content) LIKE LOWER(?) THEN 20 ELSE 0 END +
                    CASE 
                       WHEN LOWER(u.username) LIKE LOWER(?) THEN 30 ELSE 0 END +
                    CASE 
                       WHEN LOWER(c.name) LIKE LOWER(?) THEN 25 ELSE 0 END +
                    CASE 
                       WHEN LOWER(a.search_keywords) LIKE LOWER(?) THEN 15 ELSE 0 END +
                    CASE 
                       WHEN LOWER(a.meta_description) LIKE LOWER(?) THEN 5 ELSE 0 END
                   ) as relevance_score
            FROM kb_articles a
            JOIN kb_categories c ON a.category_id = c.id
            LEFT JOIN users u ON a.author_id = u.id
            WHERE a.status = 'published' $visibility_clause
              AND (
                LOWER(a.title) LIKE LOWER(?)
                OR LOWER(a.content) LIKE LOWER(?)
                OR LOWER(u.username) LIKE LOWER(?)
                OR LOWER(c.name) LIKE LOWER(?)
                OR LOWER(a.search_keywords) LIKE LOWER(?)
                OR LOWER(a.meta_description) LIKE LOWER(?)
                OR LOWER(a.excerpt) LIKE LOWER(?)
                OR DATE_FORMAT(a.published_at, '%Y-%m-%d') LIKE ?
                OR DATE_FORMAT(a.published_at, '%M %Y') LIKE ?
              )
        ";

        $search_params = [
            $like_term, $like_term, $like_term, $like_term, $like_term, $like_term, // scoring
            $like_term, $like_term, $like_term, $like_term, $like_term, $like_term, $like_term, $like_term, $like_term // filtering
        ];

        if ($category > 0) {
            $search_sql .= " AND a.category_id = ?";
            $search_params[] = $category;
        }

        switch ($sort) {
            case 'newest':
                $search_sql .= " ORDER BY a.published_at DESC";
                break;
            case 'oldest':
                $search_sql .= " ORDER BY a.published_at ASC";
                break;
            case 'most_viewed':
                $search_sql .= " ORDER BY a.view_count DESC";
                break;
            case 'alphabetical':
                $search_sql .= " ORDER BY a.title ASC";
                break;
            default: // relevance
                $search_sql .= " ORDER BY relevance_score DESC, a.view_count DESC";
                break;
        }

        $count_sql = "SELECT COUNT(*) FROM (" . $search_sql . ") as search_results";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($search_params);
        $response['total'] = $stmt->fetchColumn();

        $search_sql .= " LIMIT ?";
        $search_params[] = $limit;

        $stmt = $pdo->prepare($search_sql);
        $stmt->execute($search_params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($results as &$result) {
            $tag_stmt = $pdo->prepare("SELECT tag_name FROM kb_article_tags WHERE article_id = ? LIMIT 5");
            $tag_stmt->execute([$result['id']]);
            $result['tags'] = $tag_stmt->fetchAll(PDO::FETCH_COLUMN);

            $result['excerpt'] = $result['excerpt'] ? substr($result['excerpt'], 0, 150) . '...' : '';
            $result['url'] = '/kb/' . $result['slug'];
            $result['category_url'] = '/kb/category/' . $result['category_slug'];
            $result['formatted_date'] = date('M j, Y', strtotime($result['published_at']));
            $result['view_count'] = (int)$result['view_count'];
            $result['relevance_score'] = (int)$result['relevance_score'];
        }

        $response['results'] = $results;
        $response['type'] = 'search';

        // Suggestions if few results
        if (count($results) < 3) {
            $suggestion_sql = "
                SELECT DISTINCT a.title, a.view_count
                FROM kb_articles a
                JOIN kb_categories c ON a.category_id = c.id
                LEFT JOIN users u ON a.author_id = u.id
                WHERE a.status = 'published' $visibility_clause
                  AND (
                      LOWER(a.title) LIKE LOWER(?)
                      OR LOWER(a.search_keywords) LIKE LOWER(?)
                      OR LOWER(c.name) LIKE LOWER(?)
                  )
                ORDER BY a.view_count DESC
                LIMIT 5
            ";
            $stmt = $pdo->prepare($suggestion_sql);
            $stmt->execute(["%$query%", "%$query%", "%$query%"]);
            $response['suggestions'] = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'title');
        }
    }
} catch (Exception $e) {
    http_response_code(500);
    $response['error'] = 'Search error occurred';
    $response['debug'] = $e->getMessage(); // ⚠️ Remove this in production
    error_log("KB Search Error: " . $e->getMessage());
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>
