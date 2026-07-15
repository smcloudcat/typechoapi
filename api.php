<?php
/**
 * Author: 云猫
 * Email: yuncat@email.lwcat.cn
 * 项目地址 https://github.com/smcloudcat/typechoapi
 *
 * 适配 Typecho v1.3.0（命名空间化类名）
 */
header('Content-Type: application/json; charset=utf-8');
// token，安全验证，使用前请把下面的默认值修改成你觉得安全的
$token = "smcloudcat_test_token_2026";
error_reporting(E_ALL);

require_once 'config.inc.php';

$requestToken = $_REQUEST['token'] ?? '';

if ($token == "123456") {
    echo json_encode([
        'success' => false,
        'message' => '请先到文件设置 token'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$backupDir = __DIR__ . '/backups/';//这个是备份文件保存地址，请自行修改
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
    $db = \Typecho\Db::get();
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
            'title'        => $title,
            'slug'         => $slug ?: \Typecho\Common::slugName($title, time()),
            'created'      => \Typecho\Date::time(),
            'modified'     => \Typecho\Date::time(),
            'text'         => $content,
            'type'         => 'post',
            'status'       => 'publish',
            'authorId'     => 1,
            'commentsNum'  => 0,
            'allowComment' => 1,
            'allowPing'    => 1,
            'allowFeed'    => 1,
            'password'     => ''
        ];

        $cid = $db->query($db->insert($table)->rows($insertData));

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
                    $mid = $db->query($db->insert($prefix . 'metas')->rows([
                        'name'        => $tag,
                        'slug'        => \Typecho\Common::slugName($tag, $tag),
                        'type'        => 'tag',
                        'description' => $tag,
                        'count'       => 1
                    ]));
                } else {
                    $mid = $term['mid'];

                    $db->query($db->update($prefix . 'metas')
                        ->expression('count', 'count + 1', false)
                        ->where('mid = ?', $mid));
                }

                // 避免重复关联（cid + mid 为主键）
                $exists = $db->fetchRow($db->select('cid')
                    ->from($prefix . 'relationships')
                    ->where('cid = ?', $cid)
                    ->where('mid = ?', $mid));

                if (!$exists) {
                    $db->query($db->insert($prefix . 'relationships')->rows([
                        'cid' => $cid,
                        'mid' => $mid
                    ]));
                }
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
            ->order('created', \Typecho\Db::SORT_DESC)
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
            ->order('created', \Typecho\Db::SORT_ASC);

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
        $options = $db->fetchAll($db->select()->from($optionsTable)->where('user = ?', 0));

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

    } elseif ($method == 'delete') { // 删除文章/附件/页面（含物理文件）
        $cid = intval($_REQUEST['cid'] ?? 0);
        if (!$cid) {
            throw new Exception('内容ID不能为空');
        }

        // 先查出内容信息
        $content = $db->fetchRow($db->select('cid', 'type', 'text')
            ->from($table)->where('cid = ?', $cid));
        if (empty($content)) {
            throw new Exception('内容不存在');
        }

        // 如果是附件，先删除物理文件
        $deletedFiles = [];
        if ($content['type'] == 'attachment') {
            $attInfo = json_decode($content['text'], true);
            if (!empty($attInfo['path'])) {
                $filePath = __DIR__ . $attInfo['path'];
                if (file_exists($filePath) && is_file($filePath)) {
                    if (@unlink($filePath)) {
                        $deletedFiles[] = $attInfo['path'];
                    }
                }
                // 同时尝试清理空目录
                $dir = dirname($filePath);
                if (is_dir($dir)) {
                    $files = @scandir($dir);
                    if ($files && count($files) <= 2) { // 只有 . 和 ..
                        @rmdir($dir);
                    }
                }
            }
        }

        // 先查出关联的标签/分类，删除内容后递减其计数
        $relatedMetas = $db->fetchAll($db->select('mid')
            ->from($prefix . 'relationships')
            ->where('cid = ?', $cid));

        $db->query($db->delete($table)->where('cid = ?', $cid));
        $db->query($db->delete($prefix . 'relationships')->where('cid = ?', $cid));
        $db->query($db->delete($prefix . 'comments')->where('cid = ?', $cid));
        $db->query($db->delete($prefix . 'fields')->where('cid = ?', $cid));

        // 递减相关 metas 的 count（不删除标签/分类本身）
        foreach ($relatedMetas as $meta) {
            $db->query($db->update($prefix . 'metas')
                ->expression('count', 'count - 1', false)
                ->where('mid = ?', $meta['mid'])
                ->where('count > 0'));
        }

        // 处理附件删除：如果是页面且有子页面，子页面上移
        if ($content['type'] == 'page') {
            $db->query($db->update($table)->rows(['parent' => 0])
                ->where('parent = ?', $cid)
                ->where('type = ?', 'page'));

            // 如果删除的是首页，重置为最新文章
            $frontPage = $db->fetchRow($db->select('value')->from($prefix . 'options')
                ->where('name = ?', 'frontPage'));
            if ($frontPage && $frontPage['value'] == 'page:' . $cid) {
                $db->query($db->update($prefix . 'options')
                    ->rows(['value' => 'recent'])
                    ->where('name = ?', 'frontPage'));
            }
        }

        $msg = $content['type'] == 'attachment'
            ? '附件删除成功（含物理文件）'
            : ($content['type'] == 'page' ? '页面删除成功' : '文章删除成功');

        $resp = ['success' => true, 'message' => $msg];
        if (!empty($deletedFiles)) {
            $resp['deleted_files'] = $deletedFiles;
        }

        echo json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } elseif (in_array($method, ['backup_db', 'backup_files', 'backup_all'])) { //备份功能
        $backupType = $method;
        $timestamp = date('YmdHis');
        $randomStr = bin2hex(random_bytes(4));
        $backupName = "backup_{$timestamp}_{$randomStr}";

        $backupFiles = [];

        // 备份数据库
        if ($backupType == 'backup_db' || $backupType == 'backup_all') {
            $dbBackupFile = "{$backupDir}{$backupName}.sql";

            if (!function_exists('exec')) {
                throw new Exception('服务器已禁用 exec 函数，无法执行 mysqldump');
            }

            $dbConfig = $db->getConfig(\Typecho\Db::READ);
            $command = sprintf(
                'mysqldump -h%s -u%s -p%s %s > %s',
                escapeshellarg($dbConfig->host),
                escapeshellarg($dbConfig->user),
                escapeshellarg($dbConfig->password),
                escapeshellarg($dbConfig->database),
                escapeshellarg($dbBackupFile)
            );
            exec($command, $output, $returnVar);

            if ($returnVar !== 0 || !file_exists($dbBackupFile)) {
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
                new RecursiveDirectoryIterator(__DIR__, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            $backupRealPath = realpath($backupDir);
            foreach ($files as $name => $file) {
                if (!$file->isDir() && strpos($file->getRealPath(), $backupRealPath) !== 0) {
                    $filePath = $file->getRealPath();
                    $relativePath = substr($filePath, strlen(__DIR__) + 1);
                    $zip->addFile($filePath, $relativePath);
                }
            }

            $zip->close();
            $backupFiles[] = $fileBackup;
        }

        // 兼容性更好的 scheme 检测
        $scheme = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? '') == 443
            ? 'https' : 'http';

        $downloadLinks = [];
        foreach ($backupFiles as $file) {
            $downloadLinks[] = [
                'name' => basename($file),
                'url' => $scheme . '://' . $_SERVER['HTTP_HOST'] . str_replace($_SERVER['DOCUMENT_ROOT'], '', $file)
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
        $query = $db->select('mid', 'name', 'slug', 'count')
            ->from($prefix . 'metas')
            ->where('type = ?', 'tag')
            ->order('name', \Typecho\Db::SORT_ASC);

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
                'mid'   => (int)$tag['mid'],
                'name'  => $tag['name'],
                'slug'  => $tag['slug'],
                'count' => (int)$tag['count']
            ];
        }

        echo json_encode([
            'success' => true,
            'data' => $result
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } elseif ($method == 'getallcategories') { // 获取全部分类
        $query = $db->select('mid', 'name', 'slug', 'count', 'parent')
            ->from($prefix . 'metas')
            ->where('type = ?', 'category')
            ->order('order', \Typecho\Db::SORT_ASC)
            ->order('name', \Typecho\Db::SORT_ASC);

        $categories = $db->fetchAll($query);

        $result = [];
        foreach ($categories as $category) {
            $result[] = [
                'mid'        => (int)$category['mid'],
                'name'        => $category['name'],
                'slug'        => $category['slug'],
                'post_count'  => (int)$category['count'],
                'parent'      => (int)$category['parent']
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
            ->join($prefix . 'relationships', $table . '.cid = ' . $prefix . 'relationships.cid', \Typecho\Db::INNER_JOIN)
            ->where($prefix . 'relationships.mid = ?', $categoryInfo['mid'])
            ->where($table . '.type = ?', 'post')
            ->where($table . '.status = ?', 'publish');

        $total = $db->fetchObject($totalQuery)->num;

        $postQuery = $db->select(
            $table . '.cid',
            $table . '.title',
            $table . '.slug',
            $table . '.created',
            $table . '.authorId',
            $table . '.text'
        )
            ->from($table)
            ->join($prefix . 'relationships', $table . '.cid = ' . $prefix . 'relationships.cid', \Typecho\Db::INNER_JOIN)
            ->where($prefix . 'relationships.mid = ?', $categoryInfo['mid'])
            ->where($table . '.type = ?', 'post')
            ->where($table . '.status = ?', 'publish')
            ->order($table . '.created', \Typecho\Db::SORT_DESC)
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
            ->order('created', \Typecho\Db::SORT_DESC)
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
                "/" . preg_quote($keyword, '/') . "/i",
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

    } elseif ($method == 'getpost') { // 获取单篇文章（按 cid 或 slug）
        $cid = intval($_REQUEST['cid'] ?? 0);
        $slug = trim($_REQUEST['slug'] ?? '');

        if (empty($cid) && empty($slug)) {
            throw new Exception('cid 或 slug 不能同时为空');
        }

        $query = $db->select('cid', 'title', 'slug', 'created', 'modified', 'text',
            'type', 'status', 'authorId', 'commentsNum', 'allowComment', 'allowPing', 'allowFeed', 'password')
            ->from($table)
            ->where('type = ?', 'post');

        if ($cid) {
            $query->where('cid = ?', $cid);
        } else {
            $query->where('slug = ?', $slug);
        }

        $article = $db->fetchRow($query);
        if (empty($article)) {
            throw new Exception('文章不存在');
        }

        // 加密文章不返回正文
        if (!empty($article['password'])) {
            $article['text'] = '[加密文章，请输入密码访问]';
        }

        // 作者信息
        $author = $db->fetchRow($db->select('name', 'screenName')->from($prefix . 'users')
            ->where('uid = ?', $article['authorId']));

        // 分类
        $catQuery = $db->select($prefix . 'metas.mid', $prefix . 'metas.name', $prefix . 'metas.slug')->from($prefix . 'metas')
            ->join($prefix . 'relationships', $prefix . 'relationships.mid = ' . $prefix . 'metas.mid')
            ->where($prefix . 'relationships.cid = ?', $article['cid'])
            ->where($prefix . 'metas.type = ?', 'category');
        $categories = $db->fetchAll($catQuery);

        // 标签
        $tagQuery = $db->select($prefix . 'metas.mid', $prefix . 'metas.name', $prefix . 'metas.slug')->from($prefix . 'metas')
            ->join($prefix . 'relationships', $prefix . 'relationships.mid = ' . $prefix . 'metas.mid')
            ->where($prefix . 'relationships.cid = ?', $article['cid'])
            ->where($prefix . 'metas.type = ?', 'tag');
        $tags = $db->fetchAll($tagQuery);

        // 自定义字段
        $fieldsQuery = $db->select('name', 'type', 'str_value', 'int_value', 'float_value')
            ->from($prefix . 'fields')
            ->where('cid = ?', $article['cid']);
        $rawFields = $db->fetchAll($fieldsQuery);
        $fields = [];
        foreach ($rawFields as $f) {
            switch ($f['type']) {
                case 'int':
                    $fields[$f['name']] = (int)$f['int_value'];
                    break;
                case 'float':
                    $fields[$f['name']] = (float)$f['float_value'];
                    break;
                default:
                    $fields[$f['name']] = $f['str_value'];
                    break;
            }
        }

        $result = [
            'cid'           => $article['cid'],
            'title'         => $article['title'],
            'slug'          => $article['slug'],
            'created'       => date('Y-m-d H:i:s', $article['created']),
            'modified'      => date('Y-m-d H:i:s', $article['modified']),
            'text'          => $article['text'],
            'status'        => $article['status'],
            'author'        => [
                'uid'        => $article['authorId'],
                'name'       => $author['name'] ?? '',
                'screenName' => $author['screenName'] ?? ''
            ],
            'commentsNum'   => (int)$article['commentsNum'],
            'allowComment'  => $article['allowComment'],
            'allowPing'     => $article['allowPing'],
            'allowFeed'     => $article['allowFeed'],
            'categories'    => $categories,
            'tags'          => $tags,
            'fields'        => $fields
        ];

        echo json_encode([
            'success' => true,
            'data'    => $result
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } elseif ($method == 'updatepost') { // 更新文章
        $cid = intval($_REQUEST['cid'] ?? 0);
        if (!$cid) {
            throw new Exception('文章ID不能为空');
        }

        $existing = $db->fetchRow($db->select('cid')->from($table)->where('cid = ?', $cid));
        if (empty($existing)) {
            throw new Exception('文章不存在');
        }

        $updateData = [];
        if (isset($_REQUEST['title'])) $updateData['title'] = trim($_REQUEST['title']);
        if (isset($_REQUEST['content'])) $updateData['text'] = trim($_REQUEST['content']);
        if (isset($_REQUEST['slug']) && $_REQUEST['slug'] !== '') {
            $updateData['slug'] = trim($_REQUEST['slug']);
        }
        if (isset($_REQUEST['status'])) {
            $status = trim($_REQUEST['status']);
            if (in_array($status, ['publish', 'private', 'hidden', 'waiting'])) {
                $updateData['status'] = $status;
            }
        }
        if (isset($_REQUEST['password'])) $updateData['password'] = trim($_REQUEST['password']);

        $updateData['modified'] = \Typecho\Date::time();

        if (empty($updateData)) {
            throw new Exception('没有需要更新的字段');
        }

        $db->query($db->update($table)->rows($updateData)->where('cid = ?', $cid));

        // 更新标签
        if (isset($_REQUEST['tags'])) {
            $newTags = trim($_REQUEST['tags']);
            // 先清除旧标签关联并递减计数
            $oldRels = $db->fetchAll($db->select('mid')->from($prefix . 'relationships')
                ->where('cid = ?', $cid));
            foreach ($oldRels as $rel) {
                $oldMeta = $db->fetchRow($db->select('type', 'count')->from($prefix . 'metas')
                    ->where('mid = ?', $rel['mid']));
                if ($oldMeta && $oldMeta['type'] == 'tag') {
                    $db->query($db->update($prefix . 'metas')
                        ->expression('count', 'count - 1', false)
                        ->where('mid = ?', $rel['mid'])
                        ->where('count > 0'));
                }
            }
            $db->query($db->delete($prefix . 'relationships')->where('cid = ?', $cid));

            // 插入新标签
            if (!empty($newTags)) {
                $tagList = explode(',', $newTags);
                foreach ($tagList as $tag) {
                    $tag = trim($tag);
                    if (empty($tag)) continue;

                    $term = $db->fetchRow($db->select('mid')->from($prefix . 'metas')
                        ->where('type = ?', 'tag')->where('name = ?', $tag));

                    if (!$term) {
                        $mid = $db->query($db->insert($prefix . 'metas')->rows([
                            'name'        => $tag,
                            'slug'        => \Typecho\Common::slugName($tag, $tag),
                            'type'        => 'tag',
                            'description' => $tag,
                            'count'       => 1
                        ]));
                    } else {
                        $mid = $term['mid'];
                        $db->query($db->update($prefix . 'metas')
                            ->expression('count', 'count + 1', false)
                            ->where('mid = ?', $mid));
                    }

                    $exists = $db->fetchRow($db->select('cid')
                        ->from($prefix . 'relationships')
                        ->where('cid = ?', $cid)->where('mid = ?', $mid));
                    if (!$exists) {
                        $db->query($db->insert($prefix . 'relationships')->rows([
                            'cid' => $cid, 'mid' => $mid
                        ]));
                    }
                }
            }
        }

        echo json_encode(['success' => true, 'message' => '文章更新成功', 'cid' => $cid],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } elseif ($method == 'getpages') { // 获取独立页面列表
        $page = max(1, intval($_REQUEST['page'] ?? 1));
        $pageSize = max(1, min(50, intval($_REQUEST['pageSize'] ?? 10)));
        $offset = ($page - 1) * $pageSize;

        $totalQuery = $db->select('COUNT(*) AS count')->from($table)
            ->where('type = ?', 'page')
            ->where('status = ?', 'publish');
        $total = $db->fetchRow($totalQuery)['count'];

        $query = $db->select('cid', 'title', 'slug', 'created', 'modified', 'authorId',
            'text', 'order', 'template', 'parent', 'commentsNum')
            ->from($table)
            ->where('type = ?', 'page')
            ->where('status = ?', 'publish')
            ->order('order', \Typecho\Db::SORT_ASC)
            ->limit($pageSize)
            ->offset($offset);

        $pages = $db->fetchAll($query);

        $result = [];
        foreach ($pages as $p) {
            $result[] = [
                'cid'         => $p['cid'],
                'title'       => $p['title'],
                'slug'        => $p['slug'],
                'created'     => date('Y-m-d H:i:s', $p['created']),
                'modified'    => date('Y-m-d H:i:s', $p['modified']),
                'authorId'    => $p['authorId'],
                'order'       => (int)$p['order'],
                'template'    => $p['template'],
                'parent'      => $p['parent'],
                'commentsNum' => (int)$p['commentsNum'],
                'summary'     => mb_substr(strip_tags($p['text']), 0, 200) . '...'
            ];
        }

        echo json_encode([
            'success'    => true,
            'data'       => $result,
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'pageSize'    => $pageSize,
                'totalPages'  => ceil($total / $pageSize)
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } elseif ($method == 'addcategory') { // 新增分类
        $name = trim($_REQUEST['name'] ?? '');
        $slug = trim($_REQUEST['slug'] ?? '');
        $description = trim($_REQUEST['description'] ?? '');
        $parent = intval($_REQUEST['parent'] ?? 0);

        if (empty($name)) {
            throw new Exception('分类名称不能为空');
        }

        // 检查同名分类
        $exists = $db->fetchRow($db->select('mid')->from($prefix . 'metas')
            ->where('type = ?', 'category')
            ->where('name = ?', $name)
            ->where('parent = ?', $parent));
        if ($exists) {
            throw new Exception('该分类名称已存在');
        }

        // 取 order 最大值
        $maxOrder = $db->fetchObject($db->select(['MAX(order)' => 'm'])
            ->from($prefix . 'metas')
            ->where('type = ?', 'category')
            ->where('parent = ?', $parent))->m ?? 0;

        $mid = $db->query($db->insert($prefix . 'metas')->rows([
            'name'        => $name,
            'slug'        => $slug ?: \Typecho\Common::slugName($name, $name),
            'type'        => 'category',
            'description' => $description ?: $name,
            'count'       => 0,
            'order'       => $maxOrder + 1,
            'parent'      => $parent
        ]));

        echo json_encode(['success' => true, 'message' => '分类创建成功', 'mid' => $mid],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } elseif ($method == 'updatecategory') { // 更新分类
        $mid = intval($_REQUEST['mid'] ?? 0);
        if (!$mid) {
            throw new Exception('分类ID不能为空');
        }

        $existing = $db->fetchRow($db->select('mid')->from($prefix . 'metas')
            ->where('mid = ?', $mid)->where('type = ?', 'category'));
        if (empty($existing)) {
            throw new Exception('分类不存在');
        }

        $updateData = [];
        if (isset($_REQUEST['name'])) $updateData['name'] = trim($_REQUEST['name']);
        if (isset($_REQUEST['slug']) && $_REQUEST['slug'] !== '') {
            $updateData['slug'] = trim($_REQUEST['slug']);
        }
        if (isset($_REQUEST['description'])) $updateData['description'] = trim($_REQUEST['description']);

        if (empty($updateData)) {
            throw new Exception('没有需要更新的字段');
        }

        $db->query($db->update($prefix . 'metas')->rows($updateData)->where('mid = ?', $mid));

        echo json_encode(['success' => true, 'message' => '分类更新成功', 'mid' => $mid],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } elseif ($method == 'deletecategory') { // 删除分类
        $mid = intval($_REQUEST['mid'] ?? 0);
        if (!$mid) {
            throw new Exception('分类ID不能为空');
        }

        $existing = $db->fetchRow($db->select('mid', 'parent')->from($prefix . 'metas')
            ->where('mid = ?', $mid)->where('type = ?', 'category'));
        if (empty($existing)) {
            throw new Exception('分类不存在');
        }

        // 子分类上移
        $db->query($db->update($prefix . 'metas')->rows(['parent' => $existing['parent']])
            ->where('parent = ?', $mid)->where('type = ?', 'category'));

        // 删除关联
        $db->query($db->delete($prefix . 'relationships')->where('mid = ?', $mid));
        // 删除分类
        $db->query($db->delete($prefix . 'metas')->where('mid = ?', $mid));

        echo json_encode(['success' => true, 'message' => '分类删除成功'],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } elseif ($method == 'addtag') { // 新增标签
        $name = trim($_REQUEST['name'] ?? '');
        $slug = trim($_REQUEST['slug'] ?? '');

        if (empty($name)) {
            throw new Exception('标签名称不能为空');
        }

        $exists = $db->fetchRow($db->select('mid')->from($prefix . 'metas')
            ->where('type = ?', 'tag')->where('name = ?', $name));
        if ($exists) {
            throw new Exception('该标签已存在');
        }

        $mid = $db->query($db->insert($prefix . 'metas')->rows([
            'name'        => $name,
            'slug'        => $slug ?: \Typecho\Common::slugName($name, $name),
            'type'        => 'tag',
            'description' => $name,
            'count'       => 0
        ]));

        echo json_encode(['success' => true, 'message' => '标签创建成功', 'mid' => $mid],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } elseif ($method == 'updatetag') { // 更新标签
        $mid = intval($_REQUEST['mid'] ?? 0);
        if (!$mid) {
            throw new Exception('标签ID不能为空');
        }

        $existing = $db->fetchRow($db->select('mid')->from($prefix . 'metas')
            ->where('mid = ?', $mid)->where('type = ?', 'tag'));
        if (empty($existing)) {
            throw new Exception('标签不存在');
        }

        $updateData = [];
        if (isset($_REQUEST['name'])) $updateData['name'] = trim($_REQUEST['name']);
        if (isset($_REQUEST['slug']) && $_REQUEST['slug'] !== '') {
            $updateData['slug'] = trim($_REQUEST['slug']);
        }

        if (empty($updateData)) {
            throw new Exception('没有需要更新的字段');
        }

        $db->query($db->update($prefix . 'metas')->rows($updateData)->where('mid = ?', $mid));

        echo json_encode(['success' => true, 'message' => '标签更新成功', 'mid' => $mid],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } elseif ($method == 'deletetag') { // 删除标签
        $mid = intval($_REQUEST['mid'] ?? 0);
        if (!$mid) {
            throw new Exception('标签ID不能为空');
        }

        $existing = $db->fetchRow($db->select('mid')->from($prefix . 'metas')
            ->where('mid = ?', $mid)->where('type = ?', 'tag'));
        if (empty($existing)) {
            throw new Exception('标签不存在');
        }

        $db->query($db->delete($prefix . 'relationships')->where('mid = ?', $mid));
        $db->query($db->delete($prefix . 'metas')->where('mid = ?', $mid));

        echo json_encode(['success' => true, 'message' => '标签删除成功'],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } elseif ($method == 'getarchives') { // 获取归档（按月）
        $resource = $db->query($db->select('created')->from($table)
            ->where('type = ?', 'post')
            ->where('status = ?', 'publish')
            ->order('created', \Typecho\Db::SORT_DESC));

        $result = [];
        while ($post = $db->fetchRow($resource)) {
            $date = date('Y-m', $post['created']);
            if (isset($result[$date])) {
                $result[$date]['count']++;
            } else {
                $result[$date] = [
                    'year'  => date('Y', $post['created']),
                    'month' => date('m', $post['created']),
                    'date'  => $date,
                    'count' => 1
                ];
            }
        }

        $result = array_values($result);

        echo json_encode([
            'success' => true,
            'data'    => $result
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } elseif ($method == 'getauthor') { // 获取作者信息
        $uid = intval($_REQUEST['uid'] ?? 0);
        if (!$uid) {
            throw new Exception('用户ID不能为空');
        }

        $user = $db->fetchRow($db->select('uid', 'name', 'screenName', 'url', 'mail', 'created')
            ->from($prefix . 'users')
            ->where('uid = ?', $uid));

        if (empty($user)) {
            throw new Exception('用户不存在');
        }

        // 脱敏邮箱
        $mail = $user['mail'] ?? '';
        if (!empty($mail) && strpos($mail, '@') !== false) {
            [$mUser, $mDomain] = explode('@', $mail, 2);
            $mail = substr($mUser, 0, 2) . '***@' . $mDomain;
        }

        // 文章数
        $postCount = $db->fetchObject($db->select(['COUNT(*)' => 'num'])
            ->from($table)
            ->where('type = ?', 'post')
            ->where('status = ?', 'publish')
            ->where('authorId = ?', $uid))->num;

        $result = [
            'uid'        => $user['uid'],
            'name'       => $user['name'],
            'screenName' => $user['screenName'],
            'url'        => $user['url'],
            'mail'       => $mail,
            'created'    => date('Y-m-d H:i:s', $user['created']),
            'postCount'  => $postCount
        ];

        echo json_encode([
            'success' => true,
            'data'    => $result
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } elseif ($method == 'getauthorposts') { // 获取作者文章列表
        $uid = intval($_REQUEST['uid'] ?? 0);
        if (!$uid) {
            throw new Exception('用户ID不能为空');
        }

        $page = max(1, intval($_REQUEST['page'] ?? 1));
        $pageSize = max(1, min(50, intval($_REQUEST['pageSize'] ?? 10)));
        $offset = ($page - 1) * $pageSize;

        $totalQuery = $db->select(['COUNT(*)' => 'num'])->from($table)
            ->where('type = ?', 'post')
            ->where('status = ?', 'publish')
            ->where('authorId = ?', $uid);
        $total = $db->fetchObject($totalQuery)->num;

        $query = $db->select('cid', 'title', 'slug', 'created', 'authorId', 'text')
            ->from($table)
            ->where('type = ?', 'post')
            ->where('status = ?', 'publish')
            ->where('authorId = ?', $uid)
            ->order('created', \Typecho\Db::SORT_DESC)
            ->limit($pageSize)
            ->offset($offset);

        $articles = $db->fetchAll($query);

        $result = [];
        foreach ($articles as $article) {
            $tagQuery = $db->select('name')->from($prefix . 'metas')
                ->join($prefix . 'relationships', $prefix . 'relationships.mid = ' . $prefix . 'metas.mid')
                ->where($prefix . 'relationships.cid = ?', $article['cid'])
                ->where('type = ?', 'tag');
            $tags = $db->fetchAll($tagQuery);

            $result[] = [
                'cid'     => $article['cid'],
                'title'   => $article['title'],
                'slug'    => $article['slug'],
                'created' => date('Y-m-d H:i:s', $article['created']),
                'tags'    => array_column($tags, 'name'),
                'summary' => mb_substr(strip_tags($article['text']), 0, 200) . '...'
            ];
        }

        echo json_encode([
            'success'    => true,
            'data'       => $result,
            'pagination' => [
                'total'      => $total,
                'page'       => $page,
                'pageSize'   => $pageSize,
                'totalPages' => ceil($total / $pageSize)
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } elseif ($method == 'approvecomment' || $method == 'waitingcomment'
        || $method == 'spamcomment') { // 评论审核
        $coids = $_REQUEST['coid'] ?? [];
        if (!is_array($coids)) {
            $coids = [$coids];
        }
        $coids = array_map('intval', $coids);
        $coids = array_filter($coids);

        if (empty($coids)) {
            throw new Exception('评论ID不能为空');
        }

        $statusMap = [
            'approvecomment'  => 'approved',
            'waitingcomment'  => 'waiting',
            'spamcomment'     => 'spam'
        ];
        $targetStatus = $statusMap[$method];

        $updated = 0;
        foreach ($coids as $coid) {
            $comment = $db->fetchRow($db->select('coid', 'cid', 'status')
                ->from($prefix . 'comments')->where('coid = ?', $coid));

            if (!$comment || $comment['status'] == $targetStatus) {
                continue;
            }

            $db->query($db->update($prefix . 'comments')
                ->rows(['status' => $targetStatus])
                ->where('coid = ?', $coid));

            // 同步文章评论数
            if ($comment['status'] == 'approved' && $targetStatus != 'approved') {
                $db->query($db->update($table)
                    ->expression('commentsNum', 'commentsNum - 1', false)
                    ->where('cid = ?', $comment['cid'])
                    ->where('commentsNum > 0'));
            } elseif ($comment['status'] != 'approved' && $targetStatus == 'approved') {
                $db->query($db->update($table)
                    ->expression('commentsNum', 'commentsNum + 1', false)
                    ->where('cid = ?', $comment['cid']));
            }
            $updated++;
        }

        echo json_encode([
            'success'  => true,
            'message'  => "评论状态已更新为 {$targetStatus}",
            'updated'  => $updated
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } elseif ($method == 'deletecomment') { // 删除评论
        $coids = $_REQUEST['coid'] ?? [];
        if (!is_array($coids)) {
            $coids = [$coids];
        }
        $coids = array_map('intval', $coids);
        $coids = array_filter($coids);

        if (empty($coids)) {
            throw new Exception('评论ID不能为空');
        }

        $deleted = 0;
        foreach ($coids as $coid) {
            $comment = $db->fetchRow($db->select('coid', 'cid', 'status')
                ->from($prefix . 'comments')->where('coid = ?', $coid));

            if (!$comment) {
                continue;
            }

            $db->query($db->delete($prefix . 'comments')->where('coid = ?', $coid));

            // 已通过评论删除后，文章评论数 -1
            if ($comment['status'] == 'approved') {
                $db->query($db->update($table)
                    ->expression('commentsNum', 'commentsNum - 1', false)
                    ->where('cid = ?', $comment['cid'])
                    ->where('commentsNum > 0'));
            }
            $deleted++;
        }

        echo json_encode([
            'success' => true,
            'message' => '评论删除成功',
            'deleted' => $deleted
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } elseif ($method == 'replycomment') { // 回复评论
        $coid = intval($_REQUEST['coid'] ?? 0);
        $text = trim($_REQUEST['text'] ?? '');
        $author = trim($_REQUEST['author'] ?? 'API');
        $mail = trim($_REQUEST['mail'] ?? '');
        $url = trim($_REQUEST['url'] ?? '');

        if (!$coid || empty($text)) {
            throw new Exception('评论ID和回复内容不能为空');
        }

        $parent = $db->fetchRow($db->select('cid', 'parent')
            ->from($prefix . 'comments')->where('coid = ?', $coid));
        if (empty($parent)) {
            throw new Exception('父评论不存在');
        }

        $cid = $parent['cid'];

        $newCoid = $db->query($db->insert($prefix . 'comments')->rows([
            'cid'      => $cid,
            'created'  => \Typecho\Date::time(),
            'author'   => $author,
            'ownerId'  => 1,
            'mail'     => $mail,
            'url'      => $url,
            'ip'       => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'agent'    => $_SERVER['HTTP_USER_AGENT'] ?? 'API',
            'text'     => $text,
            'type'     => 'comment',
            'status'   => 'approved',
            'parent'   => $coid
        ]));

        // 文章评论数 +1
        $db->query($db->update($table)
            ->expression('commentsNum', 'commentsNum + 1', false)
            ->where('cid = ?', $cid));

        echo json_encode([
            'success' => true,
            'message' => '回复成功',
            'coid'    => $newCoid
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } elseif ($method == 'getstats') { // 站点统计
        $countFn = function ($type, $status = null, $extraWhere = []) use ($db, $prefix) {
            $q = $db->select(['COUNT(*)' => 'num'])->from($prefix . 'contents');
            if ($type !== null) $q->where('type = ?', $type);
            if ($status !== null) $q->where('status = ?', $status);
            foreach ($extraWhere as $w) $q->where($w[0], $w[1] ?? null);
            return (int)$db->fetchObject($q)->num;
        };

        $result = [
            'posts'           => $countFn('post', 'publish'),
            'post_drafts'     => $countFn('post_draft'),
            'post_waiting'    => $db->fetchObject($db->select(['COUNT(*)' => 'num'])
                ->from($prefix . 'contents')
                ->where('type = ? OR type = ?', 'post', 'post_draft')
                ->where('status = ?', 'waiting'))->num,
            'pages'           => $countFn('page', 'publish'),
            'page_drafts'     => $countFn('page_draft'),
            'attachments'     => $countFn('attachment'),
            'categories'      => (int)$db->fetchObject($db->select(['COUNT(*)' => 'num'])
                ->from($prefix . 'metas')->where('type = ?', 'category'))->num,
            'tags'            => (int)$db->fetchObject($db->select(['COUNT(*)' => 'num'])
                ->from($prefix . 'metas')->where('type = ?', 'tag'))->num,
            'comments_approved'   => (int)$db->fetchObject($db->select(['COUNT(*)' => 'num'])
                ->from($prefix . 'comments')->where('status = ?', 'approved'))->num,
            'comments_waiting'    => (int)$db->fetchObject($db->select(['COUNT(*)' => 'num'])
                ->from($prefix . 'comments')->where('status = ?', 'waiting'))->num,
            'comments_spam'       => (int)$db->fetchObject($db->select(['COUNT(*)' => 'num'])
                ->from($prefix . 'comments')->where('status = ?', 'spam'))->num,
        ];

        echo json_encode([
            'success' => true,
            'data'    => $result
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } elseif ($method == 'getrecentposts') { // 获取最新文章
        $limit = max(1, min(50, intval($_REQUEST['limit'] ?? 10)));

        $query = $db->select('cid', 'title', 'slug', 'created')
            ->from($table)
            ->where('type = ?', 'post')
            ->where('status = ?', 'publish')
            ->order('created', \Typecho\Db::SORT_DESC)
            ->limit($limit);

        $articles = $db->fetchAll($query);

        $result = [];
        foreach ($articles as $article) {
            $result[] = [
                'cid'     => $article['cid'],
                'title'   => $article['title'],
                'slug'    => $article['slug'],
                'created' => date('Y-m-d H:i:s', $article['created'])
            ];
        }

        echo json_encode([
            'success' => true,
            'data'    => $result
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } elseif ($method == 'getrelatedposts') { // 获取相关文章（按标签关联）
        $cid = intval($_REQUEST['cid'] ?? 0);
        $limit = max(1, min(20, intval($_REQUEST['limit'] ?? 5)));

        if (!$cid) {
            throw new Exception('文章ID不能为空');
        }

        // 取该文章的所有 tag mid
        $tagMids = $db->fetchAll($db->select('mid')->from($prefix . 'relationships')
            ->where('cid = ?', $cid));
        if (empty($tagMids)) {
            echo json_encode(['success' => true, 'data' => []],
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;
        }

        $midList = array_column($tagMids, 'mid');
        $midStr = implode(',', array_map('intval', $midList));

        // 查共享这些 mid 的其他文章（使用原生 SQL 避免 Query Builder 别名限制）
        $sql = "SELECT DISTINCT c.cid, c.title, c.slug, c.created, c.authorId"
            . " FROM {$table} c"
            . " INNER JOIN {$prefix}relationships r ON c.cid = r.cid"
            . " WHERE r.mid IN ({$midStr})"
            . " AND c.cid <> {$cid}"
            . " AND c.type = 'post'"
            . " AND c.status = 'publish'"
            . " GROUP BY c.cid"
            . " ORDER BY c.created DESC"
            . " LIMIT {$limit}";

        $articles = $db->fetchAll($db->query($sql));

        $result = [];
        foreach ($articles as $article) {
            $result[] = [
                'cid'     => $article['cid'],
                'title'   => $article['title'],
                'slug'    => $article['slug'],
                'created' => date('Y-m-d H:i:s', $article['created']),
                'authorId'=> $article['authorId']
            ];
        }

        echo json_encode([
            'success' => true,
            'data'    => $result
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } elseif ($method == 'uploadfile') { // 上传附件
        if (empty($_FILES)) {
            throw new Exception('未检测到上传文件');
        }

        $file = array_pop($_FILES);
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new Exception('文件上传失败，错误码：' . $file['error']);
        }

        // 获取扩展名
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (empty($ext)) {
            throw new Exception('无法识别文件类型');
        }

        // 读取允许的扩展名并解析（与 Typecho 一致：@image@/@media@/@doc@ 为预定义组）
        $allowedRaw = $db->fetchRow($db->select('value')->from($prefix . 'options')
            ->where('name = ?', 'attachmentTypes'))['value'] ?? '@image@';

        $allowedRaw = str_replace(
            ['@image@', '@media@', '@doc@'],
            [
                'gif,jpg,jpeg,png,tiff,bmp,webp',
                'mp3,mp4,mov,wmv,wma,rmvb,rm,avi,flv,ogg,oga,ogv',
                'txt,doc,docx,xls,xlsx,ppt,pptx,zip,rar,pdf'
            ],
            $allowedRaw
        );
        $allowedExt = array_unique(array_map('trim', preg_split("/([,.])/", $allowedRaw)));
        $allowedExt = array_map('strtolower', $allowedExt);

        if (!empty($allowedExt) && !in_array($ext, $allowedExt)) {
            throw new Exception('不支持的文件类型：' . $ext);
        }

        // 上传目录：/usr/uploads/YYYY/MM/
        $date = new \Typecho\Date();
        $uploadRoot = __DIR__ . '/usr/uploads/' . $date->year . '/' . $date->month;
        if (!is_dir($uploadRoot) && !@mkdir($uploadRoot, 0755, true)) {
            throw new Exception('无法创建上传目录');
        }

        // 生成唯一文件名
        $newName = sprintf('%u', crc32(uniqid())) . '.' . $ext;
        $destPath = $uploadRoot . '/' . $newName;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new Exception('文件移动失败');
        }

        $relPath = '/usr/uploads/' . $date->year . '/' . $date->month . '/' . $newName;

        // 获取 siteUrl
        $siteUrl = $db->fetchRow($db->select('value')->from($prefix . 'options')
            ->where('name = ?', 'siteUrl'))['value'] ?? '';
        $fileUrl = rtrim($siteUrl, '/') . $relPath;

        // 插入附件记录
        $attachmentData = [
            'name' => $file['name'],
            'path' => $relPath,
            'size' => filesize($destPath),
            'type' => $ext,
            'mime' => function_exists('mime_content_type') ? mime_content_type($destPath) : ''
        ];

        $insertData = [
            'title'       => $file['name'],
            'slug'        => $file['name'],
            'created'     => \Typecho\Date::time(),
            'modified'    => \Typecho\Date::time(),
            'text'        => json_encode($attachmentData, JSON_UNESCAPED_UNICODE),
            'type'        => 'attachment',
            'status'      => 'publish',
            'authorId'    => 1,
            'commentsNum' => 0,
            'allowComment'=> 1,
            'allowPing'   => 0,
            'allowFeed'   => 1
        ];

        // 可选关联到文章
        $parentCid = intval($_REQUEST['cid'] ?? 0);
        if ($parentCid) {
            $insertData['parent'] = $parentCid;
        }

        $cid = $db->query($db->insert($table)->rows($insertData));

        echo json_encode([
            'success' => true,
            'message' => '文件上传成功',
            'cid'     => $cid,
            'name'    => $file['name'],
            'size'    => $attachmentData['size'],
            'type'    => $ext,
            'url'     => $fileUrl
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } elseif ($method == 'getattachments') { // 获取附件列表
        $page = max(1, intval($_REQUEST['page'] ?? 1));
        $pageSize = max(1, min(50, intval($_REQUEST['pageSize'] ?? 10)));
        $offset = ($page - 1) * $pageSize;

        $total = $db->fetchRow($db->select('COUNT(*) AS count')->from($table)
            ->where('type = ?', 'attachment'))['count'];

        $query = $db->select('cid', 'title', 'slug', 'created', 'text', 'authorId', 'parent')
            ->from($table)
            ->where('type = ?', 'attachment')
            ->order('created', \Typecho\Db::SORT_DESC)
            ->limit($pageSize)
            ->offset($offset);

        $attachments = $db->fetchAll($query);

        // siteUrl
        $siteUrl = $db->fetchRow($db->select('value')->from($prefix . 'options')
            ->where('name = ?', 'siteUrl'))['value'] ?? '';

        $result = [];
        foreach ($attachments as $att) {
            $info = json_decode($att['text'], true) ?: [];
            $result[] = [
                'cid'      => $att['cid'],
                'title'    => $att['title'],
                'name'     => $info['name'] ?? $att['title'],
                'size'     => $info['size'] ?? 0,
                'type'     => $info['type'] ?? '',
                'mime'     => $info['mime'] ?? '',
                'parent'   => $att['parent'],
                'authorId' => $att['authorId'],
                'created'  => date('Y-m-d H:i:s', $att['created']),
                'url'      => rtrim($siteUrl, '/') . ($info['path'] ?? '')
            ];
        }

        echo json_encode([
            'success'    => true,
            'data'       => $result,
            'pagination' => [
                'total'      => $total,
                'page'       => $page,
                'pageSize'   => $pageSize,
                'totalPages' => ceil($total / $pageSize)
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } elseif ($method == 'methods') { // 返回全部可用 method（接口文档）
        echo json_encode([
            'success' => true,
            'data'    => [
                '文章相关' => [
                    'release'         => '发布文章 (title, content, slug?, tags?)',
                    'getarticle'       => '获取文章列表 (page?, pageSize?)',
                    'getpost'         => '获取单篇文章 (cid 或 slug)',
                    'updatepost'       => '更新文章 (cid, title?, content?, slug?, tags?, status?, password?)',
                    'delete'          => '删除文章 (cid)',
                    'search'          => '搜索文章 (keyword, page?, pageSize?)',
                    'getrecentposts'   => '最新文章 (limit?)',
                    'getrelatedposts'  => '相关文章 (cid, limit?)',
                    'getcategoryposts' => '分类下文章 (category, page?, pageSize?)'
                ],
                '分类与标签' => [
                    'getallcategories' => '获取全部分类',
                    'addcategory'      => '新增分类 (name, slug?, parent?, description?)',
                    'updatecategory'    => '更新分类 (mid, name?, slug?, description?)',
                    'deletecategory'    => '删除分类 (mid)',
                    'getalltags'       => '获取全部标签',
                    'addtag'           => '新增标签 (name, slug?)',
                    'updatetag'        => '更新标签 (mid, name?, slug?)',
                    'deletetag'        => '删除标签 (mid)'
                ],
                '页面' => [
                    'getpages' => '获取独立页面列表 (page?, pageSize?)'
                ],
                '评论' => [
                    'getcomments'      => '获取文章评论 (cid)',
                    'approvecomment'    => '通过评论 (coid 或 coid[])',
                    'waitingcomment'   => '标记待审核 (coid 或 coid[])',
                    'spamcomment'      => '标记垃圾 (coid 或 coid[])',
                    'deletecomment'    => '删除评论 (coid 或 coid[])',
                    'replycomment'     => '回复评论 (coid, text, author?, mail?, url?)'
                ],
                '用户与统计' => [
                    'getauthor'      => '获取作者信息 (uid)',
                    'getauthorposts' => '获取作者文章 (uid, page?, pageSize?)',
                    'getbloginfo'    => '获取网站信息',
                    'getstats'       => '获取站点统计',
                    'getarchives'    => '获取归档（按月）'
                ],
                '附件' => [
                    'uploadfile'      => '上传附件 (multipart file, cid?)',
                    'getattachments'  => '获取附件列表 (page?, pageSize?)'
                ],
                '备份' => [
                    'backup_db'       => '备份数据库',
                    'backup_files'    => '备份文件',
                    'backup_all'      => '备份全部',
                    'delete_backups'  => '删除所有备份'
                ],
                '其他' => [
                    'methods' => '获取所有可用接口'
                ]
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } else {
        throw new Exception('不支持的请求方法');
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
