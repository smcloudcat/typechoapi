# typechoapi

Typecho 接口 API，包括发布文章、搜索文章、获取文章、备份数据、获取文章评论、获取网站信息、获取分类、获取标签等接口，持续更新中……

## 使用教程

上传 `api.php` 文件到 Typecho 博客（只支持正式版1.2.1，其他版本不支持，包括开发版本）根目录，使用前请务必修改自定义 `token`（为了安全考虑，不修改无法使用接口）。

## 近期新增

搜索文章、获取分类、获取标签、备份数据、删除备份

## 项目地址

- [GitHub 地址](https://github.com/smcloudcat/typechoapi)
- [Gitee 地址](https://gitee.com/ximami/typechoapi)

---

## 接口文档

### [文档地址](https://lwcat.cn/markdown/typechoapi/)

### 1. 发布文章 (release)

#### 请求方法
`POST/GET`

#### 请求参数

| 参数   | 类型   | 必填 | 说明                               |
|--------|--------|------|------------------------------------|
| token  | string | 是   | 请求的认证 Token，必填，正确的 token |
| title  | string | 是   | 文章标题                           |
| content| string | 是   | 文章内容                           |
| slug   | string | 否   | 文章的 URL slug (可选)             |
| tags   | string | 否   | 文章标签，多个标签用逗号分隔 (可选) |

#### 示例请求

```bash
POST https://example.com/api.php
Content-Type: application/json

{
  "method": "release",
  "token": "123456",
  "title": "文章标题",
  "content": "文章内容",
  "slug": "article-slug",
  "tags": "PHP,Typecho"
}
```

#### 响应示例

成功响应：

```json
{
  "success": true,
  "message": "文章发布成功",
  "cid": 123
}
```

失败响应：

```json
{
  "success": false,
  "message": "无效的 token"
}
```

---

### 2. 获取文章 (getarticle)

#### 请求方法
`POST/GET`

#### 请求参数

| 参数     | 类型   | 必填 | 说明                                    |
|----------|--------|------|-----------------------------------------|
| token    | string | 是   | 请求的认证 Token，必填，正确的 token   |
| page     | int    | 否   | 页码，默认为 1                         |
| pageSize | int    | 否   | 每页数量，默认为 10，最大值为 50       |

#### 示例请求

```bash
GET https://example.com/api.php?method=getarticle&token=123456&page=1&pageSize=10
```

#### 响应示例

成功响应：

```json
{
    "success": true,
    "data": [
        {
            "cid": "134",
            "title": "测试标题",
            "slug": "测试",
            "created": "2024-12-25 22:26:14",
            "authorId": "1",
            "tags": [],
            "summary": "测试内容..."
        },
        {
            "cid": "132",
            "title": "通过c计算一元二次方程的根",
            "slug": "132",
            "created": "2024-12-08 23:45:08",
            "authorId": "1",
            "tags": [],
            "summary": "```c\r\n#include \r\n#include \r\n\r\nvoid xiao(int a,int b,int c){\r\n    int d;\r\n    float real,noreal;\r\n    d=b*b-4.0*a*c;\r\n    real=(-b)\/2*a;\r\n    noreal=sqrt(-(b*b-4*a*c));\r\n    printf(\"这个方程有两个复数根：x1=%f+%f..."
        }
    ],
    "pagination": {
        "total": "65",
        "page": 1,
        "pageSize": 2
    }
}
```

---

### 3. 获取文章评论 (getcomments)

#### 请求方法
`POST/GET`

#### 请求参数

| 参数 | 类型   | 必填 | 说明                                  |
|------|--------|------|---------------------------------------|
| token| string | 是   | 请求的认证 Token，必填，正确的 token |
| cid  | int    | 是   | 文章 ID                              |

#### 示例请求

```bash
GET https://example.com/api.php?method=getcomments&token=123456&cid=123
```

#### 响应示例

成功响应：

```json
{
  "success": true,
  "data": [
    {
      "coid": 456,
      "author": "评论者",
      "content": "这是评论内容",
      "created": "2024-12-26 10:05:00",
      "parent": 0
    }
  ]
}
```

---

### 4. 获取网站信息 (getbloginfo)

#### 请求方法
`POST/GET`

#### 请求参数

| 参数 | 类型   | 必填 | 说明                                  |
|------|--------|------|---------------------------------------|
| token| string | 是   | 请求的认证 Token，必填，正确的 token |

#### 示例请求

```bash
GET https://example.com/api.php?method=getbloginfo&token=123456
```

#### 响应示例

成功响应：

```json
{
    "success": true,
    "data": {
        "title": "CC的小窝",
        "description": "CC的小窝，记录生活的点点滴滴，分享自己的学习过程和心得。",
        "keywords": "小猫咪博客,小猫咪blog,CC的小窝",
        "theme": "handsome",
        "siteUrl": "https:\/\/lwcat.cn",
        "timezone": "28800",
        "charset": "UTF-8",
        "postCount": "65"
    }
}
```

---

### 5. 删除文章 (delete)

#### 请求方法
`POST`

#### 请求参数

| 参数   | 类型   | 必填 | 说明                                |
|--------|--------|------|-------------------------------------|
| token  | string | 是   | 请求的认证 Token，必填，正确的 token |
| cid    | int    | 是   | 要删除的文章 ID                    |

#### 示例请求

```bash
POST https://example.com/api.php
Content-Type: application/json

{
  "method": "delete",
  "token": "123456",
  "cid": 123
}
```

#### 响应示例

成功响应：

```json
{
  "success": true,
  "message": "文章删除成功"
}
```

失败响应：

```json
{
  "success": false,
  "message": "无效的 token"
}
```

---

## 错误响应

所有接口都会返回如下格式的错误响应：

```json
{
  "success": false,
  "message": "错误信息"
}
```

### 错误码

| 错误信息      | 描述                                  |
|---------------|---------------------------------------|
| `请先到文件设置 token` | Token 未设置或文件为空                |
| `无效的 token` | 请求中携带的 token 不匹配或无效      |
| `文章ID不能为空`  | 请求缺少 `cid` 或 `cid` 为无效值       |
| `不支持的请求方法`  | 请求方法不支持                       |
| `标题和内容不能为空` | 发布文章时，标题和内容不能为空        |

---

### 6. 备份管理

#### 6.1 数据库备份 (backup_db)
#### 6.2 文件备份 (backup_files) 
#### 6.3 完整备份 (backup_all)

##### 请求方法
`GET/POST`

##### 请求参数
| 参数   | 类型   | 必填 | 说明                               |
|--------|--------|------|------------------------------------|
| token  | string | 是   | 请求的认证 Token                  |
| method | string | 是   | 备份类型：backup_db/backup_files/backup_all |

##### 示例请求
```bash
GET https://example.com/api.php?method=backup_db&token=123456
```

##### 响应示例
```json
{
  "success": true,
  "message": "备份完成",
  "download_links": [
    {
      "name": "backup_202308201530_abcd1234.sql",
      "url": "https://example.com/backups/backup_202504201530_abcd1234.sql"
    }
  ]
}
```

---

### 7. 标签管理

#### 7.1 获取全部标签 (getalltags)

##### 请求方法
`GET`

##### 请求参数
| 参数   | 类型   | 必填 | 说明          |
|--------|--------|------|---------------|
| token  | string | 是   | 认证 Token   |

##### 响应示例
```json
{
  "success": true,
  "data": [
    {"name": "PHP", "slug": "php"},
    {"name": "教程", "slug": "tutorial"}
  ]
}
```

---

### 8. 分类管理

#### 8.1 获取全部分类 (getallcategories)

##### 请求方法
`GET`

##### 请求参数
| 参数   | 类型   | 必填 | 说明          |
|--------|--------|------|---------------|
| token  | string | 是   | 认证 Token   |

##### 响应示例
```json
{
  "success": true,
  "data": [
    {"name": "技术文章", "slug": "tech", "post_count": 15},
    {"name": "生活随笔", "slug": "life", "post_count": 8}
  ]
}
```

#### 8.2 获取分类文章 (getcategoryposts)

##### 请求参数
| 参数     | 类型   | 必填 | 说明                         |
|----------|--------|------|------------------------------|
| token    | string | 是   | 认证 Token                  |
| category | string | 是   | 分类名称或缩略名            |
| page     | int    | 否   | 页码（默认1）               |
| pageSize | int    | 否   | 每页数量（默认10，最大100） |

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
      "created": "2025-04-20 10:30:00",
      "authorId": 1,
      "tags": ["PHP", "后端"],
      "summary": "本文分享10个实用的PHP编程技巧..."
    }
  ],
  "pagination": {
    "total": 25,
    "page": 1,
    "pageSize": 10,
    "totalPages": 3
  }
}
```

---

### 9. 文章搜索 (search)

##### 请求参数
| 参数     | 类型   | 必填 | 说明                         |
|----------|--------|------|------------------------------|
| token    | string | 是   | 认证 Token                  |
| keyword  | string | 是   | 搜索关键词                  |
| page     | int    | 否   | 页码（默认1）               |
| pageSize | int    | 否   | 每页数量（默认10，最大100） |

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
      "created": "2025-04-20 14:30:00",
      "authorId": 1,
      "tags": ["教程", "开发"],
      "summary": "本文详细介绍如何开发Typecho API接口...",
      "highlight_summary": "本文详细介绍如何开发Typecho <span class=\"highlight\">API</span>接口..."
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

### 10. 删除备份 (delete_backups)

##### 请求方法
`POST`

##### 请求参数
| 参数   | 类型   | 必填 | 说明          |
|--------|--------|------|---------------|
| token  | string | 是   | 认证 Token   |

##### 响应示例
```json
{
  "success": true,
  "message": "已删除所有备份文件"
}
```

---

## 错误码（新增）

| 错误信息            | 描述                          |
|---------------------|-------------------------------|
| `分类不存在`         | 指定的分类名称/缩略名不存在   |
| `搜索关键词不能为空` | 未提供搜索关键词              |
| `数据库备份失败`     | 执行数据库备份时出现错误      |

---

## 其他说明

1. **Token 设置**  
   Token 存储在文件中的第 9 行，请按要求修改，禁止为 "123456"，确保文件中存在有效的 token，且在请求中正确传递。  
   如果文件为空，接口将返回 `请先到文件设置 token` 错误信息。为了你的网站安全，请设置安全的 token 并且妥善保管！

2. **分页与限制**  
   获取文章列表的接口 (`getarticle`) 支持分页，`page` 和 `pageSize` 参数允许控制返回数据的页码和每页数量。`pageSize` 最大为 50。

3. **错误处理**  
   所有接口都会返回标准的错误格式，确保你能够捕获并处理错误信息。

4. **备份管理注意事项**
   - 备份目录位于 `/backups/` 需要777写权限，可在文件中修改地址（建议修改）
   - 建议在服务器配置禁止直接访问备份目录
   - 大文件备份建议设置 `set_time_limit(0)`，否则容易出现超时问题，尤其是把图片文件都放在博客服务器中的

5. **搜索功能优化建议**
   - 在MySQL中为contents表创建全文索引
   ```sql
   CREATE FULLTEXT INDEX idx_search ON typecho_contents(title, text);
   ```
   - 中文搜索建议使用MySQL 5.7+的ngram分词器

6. **分类文章排序逻辑**
   - 默认按创建时间倒序排列
   - 可通过修改`order`参数实现不同排序方式：
   ```php
   ->order($table.'.created', Typecho_Db::SORT_DESC) // 修改此处排序条件
   ```

---

## 关于项目

最近在搞机器人，就顺手写了这个，接口目前还不多，持续更新中。如果在使用过程中发现问题，欢迎反馈 [yuncat@email.lwcat.cn](mailto:yuncat@email.lwcat.cn)
