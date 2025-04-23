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

$backupDir = __DIR__ . '/backups/';//这个是备份文件保持地址，请自行修改
if (!file_exists($backupDir)) {
    mkdir($backupDir, 0755, true);
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
        $title = isset($_REQUEST['title']) ? trim($_REQUEST['title']) : '';
        $content = isset($_REQUEST['content']) ? trim($_REQUEST['content']) : '';
        $slug = isset($_REQUEST['slug']) ? trim($_REQUEST['slug']) : '';
        $tags = isset($_REQUEST['tags']) ? trim($_REQUEST['tags']) : '';

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
        $page = max(1, intval($_REQUEST['page'] ?? 1));
        $pageSize = max(1, min(50, intval($_REQUEST['pageSize'] ?? 10)));
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
        $cid = intval($_REQUEST['cid'] ?? 0);
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

    } elseif ($method == 'delete') { // 删除文章
        $cid = intval($_REQUEST['cid'] ?? 0);
        if (!$cid) {
            throw new Exception('文章ID不能为空');
        }

        $deleteArticleQuery = $db->delete($table)->where('cid = ?', $cid);
        $db->query($deleteArticleQuery);

        $db->query($db->delete($prefix . 'relationships')->where('cid = ?', $cid));

        $db->query($db->delete($prefix . 'comments')->where('cid = ?', $cid));

        $db->query($db->delete($prefix . 'metas')->where('mid = ?', $cid));

        echo json_encode(['success' => true, 'message' => '文章删除成功'], 
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } elseif (in_array($method, ['backup_db', 'backup_files', 'backup_all'])) { //备份功能
        $backupType = $method;
        $timestamp = date('YmdHis');
        $randomStr = bin2hex(random_bytes(4));
        $backupName = "backup_{$timestamp}_{$randomStr}";
        
        $backupFiles = [];
        
        // 备份数据库
        if ($backupType == 'backup_db' || $backupType == 'backup_all') {
            $dbBackupFile = "{$backupDir}{$backupName}.sql";
            
            $dbConfig = $db->getConfig(Typecho_Db::READ);
            $command = "mysqldump -h{$dbConfig['host']} -u{$dbConfig['user']} -p{$dbConfig['password']} {$dbConfig['database']} > {$dbBackupFile}";
            exec($command, $output, $returnVar);
            
            if ($returnVar !== 0) {
                throw new Exception('数据库备份失败');
            }
            $backupFiles[] = $dbBackupFile;
        }

        // 备份文件
        if ($backupType == 'backup_files' || $backupType == 'backup_all') {
            $zip = new ZipArchive();
            $fileBackup = "{$backupDir}{$backupName}.zip";
            
            if ($zip->open($fileBackup, ZipArchive::CREATE) !== TRUE) {
                throw new Exception('无法创建压缩文件');
            }
            
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(__DIR__),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($files as $name => $file) {
                if (!$file->isDir() && !str_contains($file->getRealPath(), $backupDir)) {
                    $filePath = $file->getRealPath();
                    $relativePath = substr($filePath, strlen(__DIR__) + 1);
                    $zip->addFile($filePath, $relativePath);
                }
            }
            
            $zip->close();
            $backupFiles[] = $fileBackup;
        }

        $downloadLinks = [];
        foreach ($backupFiles as $file) {
            $downloadLinks[] = [
                'name' => basename($file),
                'url' => $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . str_replace($_SERVER['DOCUMENT_ROOT'], '', $file)
            ];
        }

        echo json_encode([
            'success' => true,
            'message' => '备份完成',
            'download_links' => $downloadLinks
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } elseif ($method == 'delete_backups') { // 删除所有备份
        $files = glob($backupDir . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => '已删除所有备份文件'
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } elseif ($method == 'getalltags') { // 获取全部标签
    $query = $db->select('name', 'slug')
        ->from($prefix . 'metas')
        ->where('type = ?', 'tag')
        ->order('name', Typecho_Db::SORT_ASC);

    $tags = $db->fetchAll($query);
    
    if (empty($tags)) {
        echo json_encode([
            'success' => true,
            'data' => []
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    $result = [];
    foreach ($tags as $tag) {
        $result[] = [
            'name' => $tag['name'],
            'slug' => $tag['slug']
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => $result
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } elseif ($method == 'getallcategories') { // 获取全部分类
    $query = $db->select('name', 'slug', 'count')
        ->from($prefix . 'metas')
        ->where('type = ?', 'category')
        ->order('order', Typecho_Db::SORT_ASC)
        ->order('name', Typecho_Db::SORT_ASC);

    $categories = $db->fetchAll($query);
    
    $result = [];
    foreach ($categories as $category) {
        $result[] = [
            'name'        => $category['name'],
            'slug'        => $category['slug'],
            'post_count'  => (int)$category['count']
        ];
    }

    echo json_encode([
        'success' => true,
        'data'    => $result
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} elseif ($method == 'getcategoryposts') { // 获取分类下文章
    $category = trim($_REQUEST['category'] ?? '');
    if (empty($category)) {
        throw new Exception('分类名称/缩略名不能为空');
    }

    $metaQuery = $db->select('mid', 'name', 'slug')
        ->from($prefix . 'metas')
        ->where('type = ?', 'category')
        ->where('(name = ? OR slug = ?)', $category, $category);
    
    $categoryInfo = $db->fetchRow($metaQuery);
    
    if (empty($categoryInfo)) {
        throw new Exception('指定分类不存在');
    }

    $page = max(1, intval($_REQUEST['page'] ?? 1));
    $pageSize = max(1, min(100, intval($_REQUEST['pageSize'] ?? 10)));
    $offset = ($page - 1) * $pageSize;

    $totalQuery = $db->select(['COUNT(*)' => 'num'])
        ->from($table)
        ->join($prefix.'relationships', $table.'.cid = '.$prefix.'relationships.cid', Typecho_Db::INNER_JOIN)
        ->where($prefix.'relationships.mid = ?', $categoryInfo['mid'])
        ->where($table.'.type = ?', 'post')
        ->where($table.'.status = ?', 'publish');
    
    $total = $db->fetchObject($totalQuery)->num;

    $postQuery = $db->select(
        $table.'.cid',
        $table.'.title',
        $table.'.slug',
        $table.'.created',
        $table.'.authorId',
        $table.'.text'
    )
    ->from($table)
    ->join($prefix.'relationships', $table.'.cid = '.$prefix.'relationships.cid', Typecho_Db::INNER_JOIN)
    ->where($prefix.'relationships.mid = ?', $categoryInfo['mid'])
    ->where($table.'.type = ?', 'post')
    ->where($table.'.status = ?', 'publish')
    ->order($table.'.created', Typecho_Db::SORT_DESC)
    ->limit($pageSize)
    ->offset($offset);

    $articles = $db->fetchAll($postQuery);

    $result = [];
    foreach ($articles as $article) {
        $tagQuery = $db->select('name')
            ->from($prefix . 'metas')
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
        'category_info' => [
            'name' => $categoryInfo['name'],
            'slug' => $categoryInfo['slug'],
            'total_posts' => $total
        ],
        'data' => $result,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'totalPages' => ceil($total / $pageSize)
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} elseif ($method == 'search') { // 文章搜索功能
    $keyword = trim($_REQUEST['keyword'] ?? '');
    if (empty($keyword)) {
        throw new Exception('搜索关键词不能为空');
    }

    $page = max(1, intval($_REQUEST['page'] ?? 1));
    $pageSize = max(1, min(100, intval($_REQUEST['pageSize'] ?? 10)));
    $offset = ($page - 1) * $pageSize;

    $searchTerm = '%' . str_replace(['%', '_'], ['\%', '\_'], $keyword) . '%';
    
    $totalQuery = $db->select(['COUNT(*)' => 'num'])
        ->from($table)
        ->where('type = ?', 'post')
        ->where('status = ?', 'publish')
        ->where('(title LIKE ? OR text LIKE ?)', $searchTerm, $searchTerm);
    
    $total = $db->fetchObject($totalQuery)->num;

    $postQuery = $db->select(
        'cid',
        'title',
        'slug',
        'created',
        'authorId',
        'text'
    )
    ->from($table)
    ->where('type = ?', 'post')
    ->where('status = ?', 'publish')
    ->where('(title LIKE ? OR text LIKE ?)', $searchTerm, $searchTerm)
    ->order('created', Typecho_Db::SORT_DESC)
    ->limit($pageSize)
    ->offset($offset);

    $articles = $db->fetchAll($postQuery);

    $result = [];
    foreach ($articles as $article) {
        $tagQuery = $db->select('name')
            ->from($prefix . 'metas')
            ->join($prefix . 'relationships', $prefix . 'relationships.mid = ' . $prefix . 'metas.mid')
            ->where($prefix . 'relationships.cid = ?', $article['cid'])
            ->where('type = ?', 'tag');
        $tags = $db->fetchAll($tagQuery);

        $highlightTitle = str_ireplace(
            $keyword, 
            "<span class=\"highlight\">{$keyword}</span>", 
            $article['title']
        );
        
        $summary = mb_substr(strip_tags($article['text']), 0, 200) . '...';
        $highlightSummary = preg_replace(
            "/$keyword/i", 
            "<span class=\"highlight\">$0</span>", 
            $summary
        );

        $result[] = [
            'cid' => $article['cid'],
            'title' => $article['title'],
            'highlight_title' => $highlightTitle,
            'slug' => $article['slug'],
            'created' => date('Y-m-d H:i:s', $article['created']),
            'authorId' => $article['authorId'],
            'tags' => array_column($tags, 'name'),
            'summary' => $summary,
            'highlight_summary' => $highlightSummary
        ];
    }

    echo json_encode([
        'success' => true,
        'keyword' => $keyword,
        'data' => $result,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'totalPages' => ceil($total / $pageSize)
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

}else {
        throw new Exception('不支持的请求方法');
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}