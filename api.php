<?php
/**
 * Author: 云猫
 * Email: yuncat@email.lwcat.cn
 * 项目地址https://github.com/smcloudcat/typechoapi
 */
header('Content-Type: application/json; charset=utf-8');
// token，安全验证，使用前请把123456修改成你觉得安全的
$token = "123456";
error_reporting(E_ALL);

require_once 'config.inc.php';

$requestToken = $_REQUEST['token'] ?? '';

if ($token=="123456") {
    echo json_encode([
        'success' => false,
        'message' => '请先到文件设置 token'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if (empty($requestToken) || $requestToken !== $token) {
    echo json_encode([
        'success' => false,
        'message' => '无效的 token'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

try {
    $db = Typecho_Db::get();
    $prefix = $db->getPrefix();
    $table = $prefix . 'contents';

    $method = $_REQUEST['method'] ?? '';

    if ($method == 'release') { // 发布文章
        $title = isset($_POST['title']) ? trim($_POST['title']) : '';
        $content = isset($_POST['content']) ? trim($_POST['content']) : '';
        $slug = isset($_POST['slug']) ? trim($_POST['slug']) : '';
        $tags = isset($_POST['tags']) ? trim($_POST['tags']) : '';

        if (empty($title) || empty($content)) {
            throw new Exception('标题和内容不能为空');
        }

        $insertData = [
            'title' => $title,
            'slug' => $slug ?: Typecho_Common::slug($title),
            'created' => time(),
            'modified' => time(),
            'text' => $content,
            'type' => 'post',
            'status' => 'publish',
            'authorId' => 1,
            'allowComment' => 1,
            'allowPing' => 1,
            'allowFeed' => 1,
            'password' => ''
        ];

        $db->query($db->insert($table)->rows($insertData));
        $cid = $db->lastInsertId();

        if (!empty($tags)) {
            $tagList = explode(',', $tags);
            foreach ($tagList as $tag) {
                $tag = trim($tag);
                if (empty($tag)) continue;

                $termQuery = $db->select('mid')->from($prefix . 'metas')
                    ->where('type = ?', 'tag')
                    ->where('name = ?', $tag);
                
                $term = $db->fetchRow($termQuery);
                
                if (!$term) {
                    $db->query($db->insert($prefix . 'metas')->rows([
                        'name' => $tag,
                        'slug' => Typecho_Common::slug($tag),
                        'type' => 'tag',
                        'count' => 1
                    ]));
                    $mid = $db->lastInsertId();
                } else {
                    $mid = $term['mid'];
                    
                    $db->query($db->update($prefix . 'metas')
                        ->rows(['count' => new Typecho_Db_Expression('count + 1')])
                        ->where('mid = ?', $mid));
                }

                $db->query($db->insert($prefix . 'relationships')->rows([
                    'cid' => $cid,
                    'mid' => $mid
                ]));
            }
        }

        echo json_encode(['success' => true, 'message' => '文章发布成功', 'cid' => $cid], 
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } elseif ($method == 'getarticle') { // 获取文章
        $page = max(1, intval($_GET['page'] ?? 1));
        $pageSize = max(1, min(50, intval($_GET['pageSize'] ?? 10)));
        $offset = ($page - 1) * $pageSize;

        $totalQuery = $db->select('COUNT(*) AS count')->from($table)
            ->where('type = ?', 'post')
            ->where('status = ?', 'publish');
        $total = $db->fetchRow($totalQuery)['count'];

        $query = $db->select('cid', 'title', 'slug', 'created', 'authorId', 'text')
            ->from($table)
            ->where('type = ?', 'post')
            ->where('status = ?', 'publish')
            ->order('created', Typecho_Db::SORT_DESC)
            ->limit($pageSize)
            ->offset($offset);

        $articles = $db->fetchAll($query);
        
        if (empty($articles)) {
            echo json_encode([
                'success' => true,
                'data' => [],
                'pagination' => [
                    'total' => $total,
                    'page' => $page,
                    'pageSize' => $pageSize
                ]
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;
        }

        $result = [];
        foreach ($articles as $article) {
            $tagQuery = $db->select('name')->from($prefix . 'metas')
                ->join($prefix . 'relationships', $prefix . 'relationships.mid = ' . $prefix . 'metas.mid')
                ->where($prefix . 'relationships.cid = ?', $article['cid'])
                ->where('type = ?', 'tag');
            $tags = $db->fetchAll($tagQuery);
            
            $result[] = [
                'cid' => $article['cid'],
                'title' => $article['title'],
                'slug' => $article['slug'],
                'created' => date('Y-m-d H:i:s', $article['created']),
                'authorId' => $article['authorId'],
                'tags' => array_column($tags, 'name'),
                'summary' => mb_substr(strip_tags($article['text']), 0, 200) . '...'
            ];
        }

        echo json_encode([
            'success' => true,
            'data' => $result,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'pageSize' => $pageSize
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } elseif ($method == 'getcomments') { // 获取文章评论
        $cid = intval($_GET['cid'] ?? 0);
        if (!$cid) {
            throw new Exception('文章ID不能为空');
        }

        $query = $db->select('coid', 'author', 'text', 'created', 'parent')
            ->from($prefix . 'comments')
            ->where('cid = ?', $cid)
            ->where('status = ?', 'approved')
            ->order('created', Typecho_Db::SORT_ASC);
        
        $comments = $db->fetchAll($query);
        
        $result = [];
        foreach ($comments as $comment) {
            $result[] = [
                'coid' => $comment['coid'],
                'author' => $comment['author'],
                'content' => $comment['text'],
                'created' => date('Y-m-d H:i:s', $comment['created']),
                'parent' => $comment['parent']
            ];
        }

        echo json_encode([
            'success' => true,
            'data' => $result
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } elseif ($method == 'getbloginfo') { // 获取网站信息
        $optionsTable = $prefix . 'options';
        $options = $db->fetchAll($db->select()->from($optionsTable));
        
        $optionsMap = [];
        foreach ($options as $option) {
            $optionsMap[$option['name']] = $option['value'];
        }
        
        $result = [
            'title' => $optionsMap['title'] ?? '',
            'description' => $optionsMap['description'] ?? '',
            'keywords' => $optionsMap['keywords'] ?? '',
            'theme' => $optionsMap['theme'] ?? '',
            'siteUrl' => $optionsMap['siteUrl'] ?? '',
            'timezone' => $optionsMap['timezone'] ?? '',
            'charset' => $optionsMap['charset'] ?? '',
            'postCount' => $db->fetchObject($db->select(['COUNT(*)' => 'num'])
                ->from($prefix . 'contents')
                ->where('type = ?', 'post')
                ->where('status = ?', 'publish'))->num
        ];

        echo json_encode([
            'success' => true,
            'data' => $result
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } else {
        throw new Exception('不支持的请求方法');
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
