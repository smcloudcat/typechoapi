# typechoapi

Typecho 博客 RESTful 风格 API 接口，一个文件即可部署。支持文章/页面/分类/标签/评论/附件/备份等全套管理功能，**已适配 Typecho v1.3.0**，持续更新中……

## 使用教程

上传 `api.php` 文件到 Typecho 博客根目录即可使用。

- **支持版本**：Typecho v1.3.0 正式版
- **PHP 版本**：建议 PHP 7.4+
- **安全配置**：使用前请务必修改 **api.php**第 11 行的 `token` 默认值为你自己的安全 token（不修改无法使用接口）

## 近期更新

- 适配 Typecho v1.3.0 命名空间化类名
- 新增 14 类接口：单篇文章查询、文章更新、独立页面、分类/标签 CRUD、归档、作者信息、评论审核、站点统计、最新/相关文章、附件上传等
- 优化删除接口：附件删除同步清理物理文件、页面删除处理子页面与首页关联
- 修复 v1.3.0 下的多个兼容性 Bug（lastInsertId、Expression 类、slug 方法等）

## 项目地址

- [GitHub 地址](https://github.com/smcloudcat/typechoapi)
- [Gitee 地址](https://gitee.com/ximami/typechoapi)

---

## 通用说明

### 请求方式

所有接口均支持 `GET` / `POST`，参数通过 query string 或 form-data 传递。上传附件（`uploadfile`）需使用 `multipart/form-data`。

### 通用参数

| 参数    | 类型   | 必填 | 说明                          |
|---------|--------|------|-------------------------------|
| token   | string | 是   | 认证 Token（需与 api.php 中一致） |
| method  | string | 是   | 接口方法名                     |

### 通用响应格式

```json
{
  "success": true,
  "message": "操作成功",
  ...
}
```

失败时 `success` 为 `false`，`message` 为错误描述。

### 调用示例

```bash
# GET 方式
GET https://example.com/api.php?method=getarticle&token=YOUR_TOKEN&page=1&pageSize=10

# POST 方式（form-data）
POST https://example.com/api.php
Content-Type: application/x-www-form-urlencoded

method=release&token=YOUR_TOKEN&title=文章标题&content=文章内容
```

### 获取接口清单

访问 `method=methods` 可在线获取全部接口的分组说明：

```bash
GET https://example.com/api.php?method=methods&token=YOUR_TOKEN
```

---

## 接口文档

### 一、文章相关

#### 1.1 发布文章 (release)

##### 请求参数

| 参数     | 类型   | 必填 | 说明                                |
|----------|--------|------|-------------------------------------|
| title    | string | 是   | 文章标题                            |
| content  | string | 是   | 文章正文                            |
| slug     | string | 否   | 文章 URL 缩略名（留空自动生成）      |
| tags     | string | 否   | 标签，多个用英文逗号分隔             |

##### 响应示例

```json
{
  "success": true,
  "message": "文章发布成功",
  "cid": 123
}
```

---

#### 1.2 获取文章列表 (getarticle)

##### 请求参数

| 参数     | 类型 | 必填 | 说明                          |
|----------|------|------|-------------------------------|
| page     | int  | 否   | 页码，默认 1                  |
| pageSize | int  | 否   | 每页数量，默认 10，最大 50    |

##### 响应示例

```json
{
  "success": true,
  "data": [
    {
      "cid": 134,
      "title": "测试标题",
      "slug": "test",
      "created": "2026-07-15 10:00:00",
      "authorId": 1,
      "tags": ["PHP", "Typecho"],
      "summary": "文章摘要前200字..."
    }
  ],
  "pagination": {
    "total": 65,
    "page": 1,
    "pageSize": 10
  }
}
```

---

#### 1.3 获取单篇文章 (getpost)

按 `cid` 或 `slug` 获取一篇文章的完整内容，含作者、分类、标签、自定义字段。加密文章不返回正文。

##### 请求参数

| 参数 | 类型   | 必填 | 说明                       |
|------|--------|------|----------------------------|
| cid  | int    | 否   | 文章 ID（与 slug 二选一）   |
| slug | string | 否   | 文章缩略名（与 cid 二选一） |

##### 响应示例

```json
{
  "success": true,
  "data": {
    "cid": 1,
    "title": "欢迎使用 Typecho",
    "slug": "start",
    "created": "2026-07-15 10:00:00",
    "modified": "2026-07-15 12:00:00",
    "text": "文章正文...",
    "status": "publish",
    "author": {
      "uid": 1,
      "name": "admin",
      "screenName": "站长"
    },
    "commentsNum": 5,
    "categories": [{"mid": 1, "name": "默认分类", "slug": "default"}],
    "tags": [{"mid": 2, "name": "PHP", "slug": "php"}],
    "fields": {"自定义字段名": "字段值"}
  }
}
```

---

#### 1.4 更新文章 (updatepost)

更新已有文章，可同时重设标签（会自动清理旧标签关联并维护计数）。

##### 请求参数

| 参数     | 类型   | 必填 | 说明                                              |
|----------|--------|------|---------------------------------------------------|
| cid      | int    | 是   | 文章 ID                                          |
| title    | string | 否   | 新标题                                            |
| content  | string | 否   | 新正文                                            |
| slug     | string | 否   | 新缩略名                                          |
| tags     | string | 否   | 新标签（逗号分隔），传空字符串可清空所有标签       |
| status   | string | 否   | 状态：publish / private / hidden / waiting        |
| password | string | 否   | 文章密码                                          |

##### 响应示例

```json
{
  "success": true,
  "message": "文章更新成功",
  "cid": 123
}
```

---

#### 1.5 删除内容 (delete)

删除文章/页面/附件。删除附件时会同步删除磁盘上的物理文件并清理空目录；删除页面时会将子页面上移，若是首页则重置为最新文章。

##### 请求参数

| 参数 | 类型 | 必填 | 说明           |
|------|------|------|----------------|
| cid  | int  | 是   | 内容 ID        |

##### 响应示例

```json
{
  "success": true,
  "message": "附件删除成功（含物理文件）",
  "deleted_files": ["/usr/uploads/2026/07/abc123.png"]
}
```

---

#### 1.6 搜索文章 (search)

##### 请求参数

| 参数     | 类型   | 必填 | 说明                          |
|----------|--------|------|-------------------------------|
| keyword  | string | 是   | 搜索关键词                    |
| page     | int    | 否   | 页码，默认 1                  |
| pageSize | int    | 否   | 每页数量，默认 10，最大 100   |

##### 响应示例

```json
{
  "success": true,
  "keyword": "API",
  "data": [
    {
      "cid": 123,
      "title": "TypechoAPI开发指南",
      "highlight_title": "Typecho <span class=\"highlight\">API</span>开发指南",
      "slug": "typecho-api",
      "created": "2026-07-15 14:30:00",
      "authorId": 1,
      "tags": ["教程"],
      "summary": "本文详细介绍...",
      "highlight_summary": "本文详细介绍..."
    }
  ],
  "pagination": {
    "total": 5,
    "page": 1,
    "pageSize": 10,
    "totalPages": 1
  }
}
```

---

#### 1.7 获取最新文章 (getrecentposts)

轻量级接口，适合侧边栏"最新文章"组件。

##### 请求参数

| 参数  | 类型 | 必填 | 说明                          |
|-------|------|------|-------------------------------|
| limit | int  | 否   | 返回数量，默认 10，最大 50    |

##### 响应示例

```json
{
  "success": true,
  "data": [
    {"cid": 123, "title": "最新文章", "slug": "new", "created": "2026-07-15 10:00:00"}
  ]
}
```

---

#### 1.8 获取相关文章 (getrelatedposts)

基于标签关联查询相关文章。

##### 请求参数

| 参数  | 类型 | 必填 | 说明                         |
|-------|------|------|------------------------------|
| cid   | int  | 是   | 文章 ID                      |
| limit | int  | 否   | 返回数量，默认 5，最大 20    |

##### 响应示例

```json
{
  "success": true,
  "data": [
    {"cid": 124, "title": "相关文章", "slug": "rel", "created": "2026-07-14 10:00:00", "authorId": 1}
  ]
}
```

---

#### 1.9 获取分类下文章 (getcategoryposts)

##### 请求参数

| 参数     | 类型   | 必填 | 说明                          |
|----------|--------|------|-------------------------------|
| category | string | 是   | 分类名称或缩略名              |
| page     | int    | 否   | 页码，默认 1                  |
| pageSize | int    | 否   | 每页数量，默认 10，最大 100   |

##### 响应示例

```json
{
  "success": true,
  "category_info": {
    "name": "技术文章",
    "slug": "tech",
    "total_posts": 25
  },
  "data": [
    {
      "cid": 123,
      "title": "PHP编程技巧",
      "slug": "php-tips",
      "created": "2026-07-15 10:30:00",
      "authorId": 1,
      "tags": ["PHP"],
      "summary": "文章摘要..."
    }
  ],
  "pagination": {"total": 25, "page": 1, "pageSize": 10, "totalPages": 3}
}
```

---

### 二、分类管理

#### 2.1 获取全部分类 (getallcategories)

##### 响应示例

```json
{
  "success": true,
  "data": [
    {"mid": 1, "name": "默认分类", "slug": "default", "post_count": 15, "parent": 0},
    {"mid": 2, "name": "技术文章", "slug": "tech", "post_count": 8, "parent": 1}
  ]
}
```

#### 2.2 新增分类 (addcategory)

##### 请求参数

| 参数        | 类型   | 必填 | 说明                              |
|-------------|--------|------|-----------------------------------|
| name        | string | 是   | 分类名称                          |
| slug        | string | 否   | 缩略名（留空自动生成）             |
| parent      | int    | 否   | 父分类 ID，默认 0（顶层）          |
| description | string | 否   | 分类描述                          |

#### 2.3 更新分类 (updatecategory)

##### 请求参数

| 参数        | 类型   | 必填 | 说明           |
|-------------|--------|------|----------------|
| mid         | int    | 是   | 分类 ID        |
| name        | string | 否   | 新名称         |
| slug        | string | 否   | 新缩略名       |
| description | string | 否   | 新描述         |

#### 2.4 删除分类 (deletecategory)

删除分类后，其子分类会自动上移到被删分类的父级。

##### 请求参数

| 参数 | 类型 | 必填 | 说明       |
|------|------|------|------------|
| mid  | int  | 是   | 分类 ID    |

---

### 三、标签管理

#### 3.1 获取全部标签 (getalltags)

##### 响应示例

```json
{
  "success": true,
  "data": [
    {"mid": 1, "name": "PHP", "slug": "php", "count": 12},
    {"mid": 2, "name": "教程", "slug": "tutorial", "count": 5}
  ]
}
```

#### 3.2 新增标签 (addtag)

##### 请求参数

| 参数  | 类型   | 必填 | 说明                       |
|-------|--------|------|----------------------------|
| name  | string | 是   | 标签名称                   |
| slug  | string | 否   | 缩略名（留空自动生成）      |

#### 3.3 更新标签 (updatetag)

##### 请求参数

| 参数  | 类型   | 必填 | 说明        |
|-------|--------|------|-------------|
| mid   | int    | 是   | 标签 ID     |
| name  | string | 否   | 新名称      |
| slug  | string | 否   | 新缩略名    |

#### 3.4 删除标签 (deletetag)

删除标签时同步清理与文章的关联关系。

##### 请求参数

| 参数 | 类型 | 必填 | 说明       |
|------|------|------|------------|
| mid  | int  | 是   | 标签 ID    |

---

### 四、独立页面

#### 4.1 获取页面列表 (getpages)

##### 请求参数

| 参数     | 类型 | 必填 | 说明                          |
|----------|------|------|-------------------------------|
| page     | int  | 否   | 页码，默认 1                  |
| pageSize | int  | 否   | 每页数量，默认 10，最大 50    |

##### 响应示例

```json
{
  "success": true,
  "data": [
    {
      "cid": 2,
      "title": "关于我",
      "slug": "about",
      "created": "2026-07-15 10:00:00",
      "modified": "2026-07-15 10:00:00",
      "authorId": 1,
      "order": 0,
      "template": "page-about.php",
      "parent": 0,
      "commentsNum": 3,
      "summary": "页面内容摘要..."
    }
  ],
  "pagination": {"total": 3, "page": 1, "pageSize": 10, "totalPages": 1}
}
```

---

### 五、评论管理

#### 5.1 获取文章评论 (getcomments)

##### 请求参数

| 参数 | 类型 | 必填 | 说明       |
|------|------|------|------------|
| cid  | int  | 是   | 文章 ID    |

##### 响应示例

```json
{
  "success": true,
  "data": [
    {
      "coid": 456,
      "author": "访客",
      "content": "评论内容",
      "created": "2026-07-15 10:05:00",
      "parent": 0
    }
  ]
}
```

#### 5.2 评论审核 (approvecomment / waitingcomment / spamcomment)

将评论状态改为已通过 / 待审核 / 垃圾。自动同步文章的 `commentsNum` 计数。

##### 请求参数

| 参数 | 类型       | 必填 | 说明                          |
|------|------------|------|-------------------------------|
| coid | int/array  | 是   | 评论 ID，支持单个或数组批量    |

#### 5.3 删除评论 (deletecomment)

##### 请求参数

| 参数 | 类型       | 必填 | 说明                          |
|------|------------|------|-------------------------------|
| coid | int/array  | 是   | 评论 ID，支持单个或数组批量    |

#### 5.4 回复评论 (replycomment)

##### 请求参数

| 参数    | 类型   | 必填 | 说明                          |
|---------|--------|------|-------------------------------|
| coid    | int    | 是   | 被回复的父评论 ID             |
| text    | string | 是   | 回复内容                       |
| author  | string | 否   | 回复者名称，默认 "API"         |
| mail    | string | 否   | 回复者邮箱                     |
| url     | string | 否   | 回复者网址                     |

---

### 六、用户与统计

#### 6.1 获取作者信息 (getauthor)

##### 请求参数

| 参数 | 类型 | 必填 | 说明     |
|------|------|------|----------|
| uid  | int  | 是   | 用户 ID  |

##### 响应示例

```json
{
  "success": true,
  "data": {
    "uid": 1,
    "name": "admin",
    "screenName": "站长",
    "url": "https://example.com",
    "mail": "ad***@example.com",
    "created": "2026-07-01 10:00:00",
    "postCount": 65
  }
}
```

> 邮箱已脱敏，不会暴露完整地址。

#### 6.2 获取作者文章 (getauthorposts)

##### 请求参数

| 参数     | 类型 | 必填 | 说明                          |
|----------|------|------|-------------------------------|
| uid      | int  | 是   | 用户 ID                       |
| page     | int  | 否   | 页码，默认 1                  |
| pageSize | int  | 否   | 每页数量，默认 10，最大 50    |

#### 6.3 获取网站信息 (getbloginfo)

##### 响应示例

```json
{
  "success": true,
  "data": {
    "title": "CC的小窝",
    "description": "记录生活的点点滴滴",
    "keywords": "博客,Typecho",
    "theme": "handsome",
    "siteUrl": "https://example.com",
    "timezone": "28800",
    "charset": "UTF-8",
    "postCount": 65
  }
}
```

#### 6.4 获取站点统计 (getstats)

##### 响应示例

```json
{
  "success": true,
  "data": {
    "posts": 65,
    "post_drafts": 3,
    "post_waiting": 1,
    "pages": 4,
    "page_drafts": 0,
    "attachments": 12,
    "categories": 5,
    "tags": 28,
    "comments_approved": 156,
    "comments_waiting": 2,
    "comments_spam": 8
  }
}
```

#### 6.5 获取归档 (getarchives)

按月统计已发布文章数量。

##### 响应示例

```json
{
  "success": true,
  "data": [
    {"year": "2026", "month": "07", "date": "2026-07", "count": 8},
    {"year": "2026", "month": "06", "date": "2026-06", "count": 12}
  ]
}
```

---

### 七、附件管理

#### 7.1 上传附件 (uploadfile)

需使用 `multipart/form-data`，文件字段名为 `file`。扩展名白名单读取 Typecho 后台 `attachmentTypes` 配置（`@image@` 表示图片组）。

##### 请求参数

| 参数 | 类型 | 必填 | 说明                                  |
|------|------|------|---------------------------------------|
| file | file | 是   | 上传的文件（multipart）                |
| cid  | int  | 否   | 关联的文章 ID（可选）                  |

##### 响应示例

```json
{
  "success": true,
  "message": "文件上传成功",
  "cid": 23,
  "name": "test.png",
  "size": 1024,
  "type": "png",
  "url": "https://example.com/usr/uploads/2026/07/abc123.png"
}
```

#### 7.2 获取附件列表 (getattachments)

##### 请求参数

| 参数     | 类型 | 必填 | 说明                          |
|----------|------|------|-------------------------------|
| page     | int  | 否   | 页码，默认 1                  |
| pageSize | int  | 否   | 每页数量，默认 10，最大 50    |

##### 响应示例

```json
{
  "success": true,
  "data": [
    {
      "cid": 23,
      "title": "test.png",
      "name": "test.png",
      "size": 1024,
      "type": "png",
      "mime": "image/png",
      "parent": 0,
      "authorId": 1,
      "created": "2026-07-15 10:00:00",
      "url": "https://example.com/usr/uploads/2026/07/abc123.png"
    }
  ],
  "pagination": {"total": 12, "page": 1, "pageSize": 10, "totalPages": 2}
}
```

---

### 八、备份管理

#### 8.1 数据库备份 (backup_db)
#### 8.2 文件备份 (backup_files)
#### 8.3 完整备份 (backup_all)

##### 请求方法
`GET/POST`

##### 请求参数

| 参数   | 类型   | 必填 | 说明                                          |
|--------|--------|------|-----------------------------------------------|
| method | string | 是   | 备份类型：backup_db / backup_files / backup_all |

##### 响应示例

```json
{
  "success": true,
  "message": "备份完成",
  "download_links": [
    {
      "name": "backup_202607151530_abcd1234.sql",
      "url": "https://example.com/backups/backup_202607151530_abcd1234.sql"
    }
  ]
}
```

#### 8.4 删除所有备份 (delete_backups)

##### 请求方法
`POST`

##### 响应示例

```json
{
  "success": true,
  "message": "已删除所有备份文件"
}
```

---

### 九、其他

#### 9.1 获取接口清单 (methods)

返回全部接口的分组说明，便于客户端动态发现接口。

```bash
GET https://example.com/api.php?method=methods&token=YOUR_TOKEN
```

---

## 错误响应

所有接口在出错时返回统一格式：

```json
{
  "success": false,
  "message": "错误信息描述"
}
```

### 常见错误信息

| 错误信息                   | 触发场景                                    |
|---------------------------|---------------------------------------------|
| `请先到文件设置 token`      | Token 仍为默认值 "123456" 未修改             |
| `无效的 token`             | 请求携带的 token 与配置不一致                |
| `不支持的请求方法`          | method 参数值不在支持列表中                  |
| `内容不存在` / `文章不存在`  | cid 对应的记录不存在                         |
| `分类名称不能为空` 等       | 必填参数缺失                                 |
| `不支持的文件类型：xxx`      | 上传文件扩展名不在白名单内                   |
| `服务器已禁用 exec 函数`     | 备份接口检测到 exec 不可用                   |

---

## 其他说明

1. **Token 设置**  
   Token 位于 [api.php](file:///g:/github/typechoapi/api.php#L11) 第 11 行，禁止使用默认值 `123456` 或 `smcloudcat_test_token_2026`。请设置为足够长度的随机字符串并妥善保管。Token 未修改时接口会拒绝所有请求。

2. **分页与限制**  
   所有列表接口均支持 `page` 与 `pageSize`，并对 `pageSize` 做了上限保护（文章列表 50，分类/搜索 100，最新文章 50，相关文章 20），防止恶意拉取。

3. **备份管理注意事项**
   - 备份目录位于 `/backups/`，需要 777 写权限，可在 [api.php](file:///g:/github/typechoapi/api.php#L13) 第 13 行修改路径
   - 建议在服务器（Nginx/Apache）配置禁止直接访问备份目录
   - 大文件备份建议设置 `set_time_limit(0)`，避免超时
   - 数据库备份依赖 `mysqldump`，若服务器禁用 `exec` 会报错

4. **附件上传注意事项**
   - 扩展名白名单读取 Typecho 后台"控制台 → 网站 → 文件上传"的配置
   - `@image@` / `@media@` / `@doc@` 为预定义组，分别对应图片/媒体/文档类
   - 文件保存到 `/usr/uploads/YYYY/MM/`，与 Typecho 原生上传逻辑一致
   - 删除附件（`delete`）会同步删除物理文件

5. **搜索功能优化建议**  
   在 MySQL 中为 contents 表创建全文索引可大幅提升搜索性能：
   ```sql
   CREATE FULLTEXT INDEX idx_search ON typecho_contents(title, text);
   ```
   中文搜索建议使用 MySQL 5.7+ 的 ngram 分词器。

6. **数据一致性**  
   所有涉及分类/标签关联变更的操作（发布、更新、删除文章，标签/分类 CRUD）均会自动维护 `metas.count` 字段，确保分类和标签的文章数统计准确。

---

## 关于项目

如果在使用过程中发现问题或有功能建议，欢迎反馈至 [yuncat@email.lwcat.cn](mailto:yuncat@email.lwcat.cn)
