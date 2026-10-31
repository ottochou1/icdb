<?php
/** 配置、首页、课程、短评 */

/** 允许作为筛选项读取的课程表字段（白名单，列名不来自外部参数） */
function course_facet_fields()
{
    return [
        'category'  => 'categories',
        'college'   => 'colleges',
        'credit'    => 'credits',
        'exam_mode' => 'examModes',
        'language'  => 'languages',
    ];
}

/**
 * 取课程表里各筛选字段的真实取值 + 每个值的课程数。
 * 这是前端下拉栏的唯一数据源：课程类别、开课部门这些值由教务表格导入决定，
 * 一旦写死，下拉里就会出现库里不存在的选项，选了必然搜不到课。
 */
function course_facets()
{
    $out = [];
    foreach (course_facet_fields() as $column => $key) {
        $items = [];
        try {
            $rows = q_all(
                'SELECT c.`' . $column . '` AS v, COUNT(*) AS n FROM ' . t('courses') . ' c
                 WHERE c.`' . $column . '` IS NOT NULL AND c.`' . $column . '` <> \'\'
                 GROUP BY c.`' . $column . '` ORDER BY n DESC, c.`' . $column . '` ASC'
            );
        } catch (Throwable $e) {
            $rows = [];
        }
        foreach ($rows as $r) {
            $v = trim((string) ($r['v'] ?? ''));
            if ($v === '') {
                continue;
            }
            $items[] = ['value' => $v, 'count' => (int) ($r['n'] ?? 0)];
        }
        $out[$key] = $items;
    }
    return $out;
}

/**
 * 取某个字段的去重取值（不带计数），用于建课表单的候选。
 * 库里还没有数据时回落到 $fallback，保证全新安装也有得选。
 */
function course_distinct_values($column, array $fallback)
{
    $fields = course_facet_fields();
    if (!isset($fields[$column])) {
        return $fallback;
    }
    $values = [];
    try {
        $rows = q_all(
            'SELECT DISTINCT c.`' . $column . '` AS v FROM ' . t('courses') . ' c
         WHERE c.`' . $column . '` IS NOT NULL AND c.`' . $column . '` <> \'\'
         ORDER BY c.`' . $column . '` ASC'
        );
    } catch (Throwable $e) {
        $rows = [];
    }
    foreach ($rows as $r) {
        $v = trim((string) ($r['v'] ?? ''));
        if ($v !== '') {
            $values[] = $v;
        }
    }
    return $values ? $values : $fallback;
}

function config_data()
{
    static $data = null;
    if ($data === null) {
        $data = [
            'categories' => course_distinct_values('category', ['专业必修', '专业选修', '通识必修', '通识选修', '公共体育', '外语', '思政', '实践环节', '其他']),
            'colleges' => course_distinct_values('college', ['法律学院', '经济法学院', '国际法学院', '刑事司法学院', '知识产权学院', '商学院', '政治学与公共管理学院', '传播学院', '外语学院', '社会发展学院', '马克思主义学院', '体育部', '其他']),
            'reviewTags' => ['给分好', '点名少', '作业少', '硬核', '水课', '有意思', '老师好', '考试难', '签到严', '展示多'],
            'chatTags' => ['闲聊', '吐槽', '求助', '二手', '拼课', '选课咨询', '其他'],
        ];
    }
    return $data;
}

function h_config()
{
    return config_data();
}

/** 课程检索的筛选选项（每个选项带课程数），供前端渲染下拉栏 */
function h_courses_facets()
{
    return ['facets' => course_facets()];
}

/* ------------------------- 首页聚合 ------------------------- */

function course_query_sql()
{
    return 'SELECT c.*,
        (SELECT COUNT(*) FROM ' . t('reviews') . ' r WHERE r.course_id = c.id) AS review_count,
        (SELECT COUNT(*) FROM ' . t('posts') . ' p WHERE p.course_id = c.id) AS post_count,
        (SELECT COALESCE(AVG(r.rating), 0) FROM ' . t('reviews') . ' r WHERE r.course_id = c.id) AS avg_rating
        FROM ' . t('courses') . ' c';
}

function h_home()
{
    $viewer = current_user();
    $sql = course_query_sql();

    $stats = [
        'courseCount' => (int) q_val('SELECT COUNT(*) FROM ' . t('courses')),
        'reviewCount' => (int) q_val('SELECT COUNT(*) FROM ' . t('reviews')),
        'postCount' => (int) q_val('SELECT COUNT(*) FROM ' . t('posts')),
        'userCount' => (int) q_val('SELECT COUNT(*) FROM ' . t('users')),
    ];

    // MySQL 不能在 WHERE 中引用 SELECT 别名，用 HAVING 过滤
    $topCourses = q_all($sql . ' HAVING review_count > 0 ORDER BY avg_rating DESC, review_count DESC LIMIT 6');
    $hotCourses = q_all($sql . ' ORDER BY (review_count + post_count * 2) DESC, c.created_at DESC LIMIT 6');
    $newCourses = q_all($sql . ' ORDER BY c.created_at DESC LIMIT 6');

    $latestReviews = q_all('SELECT * FROM ' . t('reviews') . ' ORDER BY created_at DESC LIMIT 6');
    $latestPosts = q_all('SELECT * FROM ' . t('posts') . ' ORDER BY created_at DESC LIMIT 8');

    return [
        'stats' => $stats,
        'topCourses' => array_map(function ($r) use ($viewer) { return course_dto($r, $viewer); }, $topCourses),
        'hotCourses' => array_map(function ($r) use ($viewer) { return course_dto($r, $viewer); }, $hotCourses),
        'newCourses' => array_map(function ($r) use ($viewer) { return course_dto($r, $viewer); }, $newCourses),
        'latestReviews' => array_map(function ($r) use ($viewer) { return review_dto($r, $viewer); }, $latestReviews),
        'latestPosts' => array_map(function ($r) use ($viewer) { return post_dto($r, $viewer); }, $latestPosts),
    ];
}

/* ------------------------- 课程 ------------------------- */

function h_courses_list()
{
    $viewer = current_user();
    $q = param_str('q', '', 60);
    $category = param_str('category', '', 20);
    $college = param_str('college', '', 30);
    $teacher = param_str('teacher', '', 30);
    $credit = param_str('credit', '', 8);
    $examMode = param_str('examMode', '', 20);
    $language = param_str('language', '', 20);
    $minRating = param_str('minRating', '', 4);
    $sort = param_str('sort', 'popular', 16);
    $page = param_int('page', 1, 1, 9999);
    $pageSize = param_int('pageSize', 12, 1, 60);

    $where = [];
    $params = [];
    if ($q !== '') {
        // 支持空格/顿号分词，各词之间是 AND：搜「刑法 李」= 课名含刑法 且 老师含李
        $words = preg_split('/[\s,，、;；]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_slice($words, 0, 5);
        if (!$words) {
            $words = [$q];
        }
        foreach ($words as $word) {
            $kw = '%' . like_escape($word) . '%';
            // 课程代码可能一对多（同名同老师合并后），所以额外去 course_codes 里比对
            $where[] = '(c.name LIKE ? OR c.code LIKE ? OR c.teacher LIKE ? OR c.college LIKE ? OR c.intro LIKE ?
                     OR EXISTS (SELECT 1 FROM ' . t('course_codes') . ' cc WHERE cc.course_id = c.id AND cc.code LIKE ?))';
            $params = array_merge($params, [$kw, $kw, $kw, $kw, $kw, $kw]);
        }
    }
    if ($category !== '') {
        $where[] = 'c.category = ?';
        $params[] = $category;
    }
    if ($college !== '') {
        $where[] = 'c.college = ?';
        $params[] = $college;
    }
    if ($teacher !== '') {
        $where[] = 'c.teacher LIKE ?';
        $params[] = '%' . like_escape($teacher) . '%';
    }
    if ($credit !== '') {
        $where[] = 'c.credit = ?';
        $params[] = $credit;
    }
    if ($examMode !== '') {
        $where[] = 'c.exam_mode = ?';
        $params[] = $examMode;
    }
    if ($language !== '') {
        $where[] = 'c.language = ?';
        $params[] = $language;
    }
    if ($minRating !== '' && is_numeric($minRating)) {
        // 平均分是 SELECT 子查询的别名，MySQL 不允许在 WHERE 里引用别名，这里直接内联同一段子查询
        $where[] = '(SELECT COALESCE(AVG(r.rating), 0) FROM ' . t('reviews') . ' r WHERE r.course_id = c.id) >= ?';
        $params[] = (float) $minRating;
    }

    $sql = course_query_sql();
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $orderMap = [
        'rating' => 'review_count DESC, avg_rating DESC',
        'top' => 'avg_rating DESC, review_count DESC',
        'popular' => '(review_count + post_count * 2) DESC',
        'discuss' => 'post_count DESC',
        'newest' => 'c.created_at DESC',
        'credit' => 'c.credit DESC',
    ];
    $sql .= ' ORDER BY ' . (isset($orderMap[$sort]) ? $orderMap[$sort] : $orderMap['popular']) . ', c.created_at DESC';

    $total = (int) q_val(
        'SELECT COUNT(*) FROM ' . t('courses') . ' c' . ($where ? ' WHERE ' . implode(' AND ', $where) : ''),
        $params
    );
    $offset = ($page - 1) * $pageSize;
    $rows = q_all($sql . ' LIMIT ' . (int) $pageSize . ' OFFSET ' . (int) $offset, $params);

    return [
        'items' => array_map(function ($r) use ($viewer) { return course_dto($r, $viewer); }, $rows),
        'total' => $total,
        'page' => $page,
        'pageSize' => $pageSize,
        'pageCount' => max(1, (int) ceil($total / $pageSize)),
    ];
}

function h_courses_get()
{
    $viewer = current_user();
    $id = param_str('id', '', 40);
    $row = find_or_fail(t('courses'), $id, '课程不存在');
    return ['course' => course_dto($row, $viewer)];
}

function h_courses_create()
{
    $user = require_verified();
    $name = param_str('name', '', 80);
    $teacher = param_str('teacher', '', 40);
    $code = param_str('code', '', 32);
    if (mb_strlen($name) < 2) {
        throw new ApiException('请填写课程名称');
    }
    if ($teacher === '') {
        throw new ApiException('请填写老师姓名');
    }
    if (course_exists($name, $teacher, $code)) {
        throw new ApiException('这门课已经存在啦，直接去评分吧', 409);
    }

    // 手工添加时也可能一次填多个代码（用顿号 / 逗号分隔）
    $extraCodes = preg_split('/[、，,;；]+/u', (string) param_raw('codes') ?: '') ?: [];
    $codes = array_values(array_filter(array_merge([$code], $extraCodes), function ($c) {
        return trim((string) $c) !== '';
    }));

    $id = new_id('c_');
    q(
        'INSERT INTO ' . t('courses') . ' (id, name, teacher, code, credit, category, exam_mode, language, college, intro, created_by, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            $id, $name, $teacher, $code,
            param_str('credit', '', 8),
            param_str('category', '其他', 20),
            param_str('examMode', '', 20),
            param_str('language', '', 20),
            param_str('college', '', 30),
            param_str('intro', '', 1200),
            $user['id'], now_ms(), now_ms(),
        ]
    );
    set_course_codes($id, $codes);
    return ['course' => course_dto(find_or_fail(t('courses'), $id, '课程不存在'), $user)];
}

function course_exists($name, $teacher, $code)
{
    if ($code !== '') {
        return (bool) q_val(
            'SELECT COUNT(*) FROM ' . t('courses') . ' WHERE name = ? AND teacher = ? AND code = ?',
            [$name, $teacher, $code]
        );
    }
    return (bool) q_val(
        'SELECT COUNT(*) FROM ' . t('courses') . ' WHERE name = ? AND teacher = ?',
        [$name, $teacher]
    );
}

function h_courses_update()
{
    $user = require_login();
    $id = param_str('id', '', 40);
    $row = find_or_fail(t('courses'), $id, '课程不存在');
    if ($row['created_by'] !== $user['id'] && empty($user['is_admin'])) {
        throw new ApiException('只有课程的添加者可以修改', 403);
    }
    $fields = [
        'name' => 80, 'teacher' => 40, 'code' => 32, 'credit' => 8,
        'category' => 20, 'examMode' => 20, 'language' => 20, 'college' => 30, 'intro' => 1200,
    ];
    $sets = [];
    $params = [];
    foreach ($fields as $key => $max) {
        $value = param_raw($key);
        if ($value !== null) {
            $sets[] = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $key)) . ' = ?';
            $params[] = param_str($key, '', $max);
        }
    }
    if (!$sets) {
        return ['course' => course_dto($row, $user)];
    }
    $sets[] = 'updated_at = ?';
    $params[] = now_ms();
    $params[] = $id;
    q('UPDATE ' . t('courses') . ' SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);

    // codes 是数组，单独处理：传了就整体覆盖
    $codesIn = param_raw('codes');
    if (is_array($codesIn)) {
        set_course_codes($id, $codesIn);
    } elseif (isset($_POST['codes'])) {
        set_course_codes($id, preg_split('/[、，,;；]+/u', (string) $_POST['codes']) ?: []);
    }

    return ['course' => course_dto(find_or_fail(t('courses'), $id, '课程不存在'), $user)];
}

function h_courses_delete()
{
    $user = require_login();
    $id = param_str('id', '', 40);
    $row = find_or_fail(t('courses'), $id, '课程不存在');
    if ($row['created_by'] !== $user['id'] && empty($user['is_admin'])) {
        throw new ApiException('只有课程的添加者可以删除', 403);
    }
    $reviewIds = q_all('SELECT id FROM ' . t('reviews') . ' WHERE course_id = ?', [$id]);
    $postIds = q_all('SELECT id FROM ' . t('posts') . ' WHERE course_id = ?', [$id]);
    $ids = [];
    foreach (array_merge($reviewIds, $postIds) as $r) {
        $ids[] = $r['id'];
    }
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        q('DELETE FROM ' . t('likes') . ' WHERE target_id IN (' . $placeholders . ')', $ids);
    }
    q('DELETE FROM ' . t('courses') . ' WHERE id = ?', [$id]);
    return ['ok' => true];
}

function h_courses_import()
{
    $user = require_verified();
    $csv = (string) param_raw('csv', '');
    $rows = csv_to_courses($csv);
    if (!$rows) {
        throw new ApiException('没有解析到有效的课程数据');
    }
    $created = 0;
    $skipped = [];
    $now = now_ms();
    foreach (array_slice($rows, 0, 500) as $item) {
        $name = mb_substr(trim((string) ($item['name'] ?? '')), 0, 80);
        $teacher = mb_substr(trim((string) ($item['teacher'] ?? '')), 0, 40);
        if ($name === '' || $teacher === '') {
            $skipped[] = ['name' => $name ?: '(空行)', 'reason' => '缺少课程名称或老师'];
            continue;
        }
        $code = mb_substr(trim((string) ($item['code'] ?? '')), 0, 32);
        if (course_exists($name, $teacher, $code)) {
            $skipped[] = ['name' => $name, 'reason' => '已存在'];
            continue;
        }
        q(
            'INSERT INTO ' . t('courses') . ' (id, name, teacher, code, credit, category, college, intro, created_by, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [
                new_id('c_'), $name, $teacher, $code,
                mb_substr((string) ($item['credit'] ?? ''), 0, 8),
                mb_substr((string) ($item['category'] ?? ''), 0, 20) ?: '其他',
                mb_substr((string) ($item['college'] ?? ''), 0, 30),
                mb_substr((string) ($item['intro'] ?? ''), 0, 1200),
                $user['id'], $now, $now,
            ]
        );
        $created++;
    }
    return ['created' => $created, 'skipped' => $skipped];
}

function h_courses_export()
{
    $rows = q_all('SELECT name, teacher, code, credit, college, category, intro FROM ' . t('courses') . ' ORDER BY created_at DESC');
    return ['csv' => courses_to_csv($rows)];
}

/* ------------------------- 短评 ------------------------- */

function h_reviews_list()
{
    $viewer = current_user();
    $courseId = param_str('id', param_str('courseId', param_str('course_id', '', 40), 40), 40);
    $sort = param_str('sort', 'newest', 16);
    $rating = param_int('rating', 0, 1, 5);

    $where = 'WHERE r.course_id = ?';
    $params = [$courseId];
    if ($rating) {
        $where .= ' AND r.rating = ?';
        $params[] = $rating;
    }
    $order = 'r.created_at DESC';
    if ($sort === 'helpful') {
        $order = '(SELECT COUNT(*) FROM ' . t('likes') . ' l WHERE l.target_type = "review" AND l.target_id = r.id) DESC, r.created_at DESC';
    } elseif ($sort === 'high') {
        $order = 'r.rating DESC, r.created_at DESC';
    } elseif ($sort === 'low') {
        $order = 'r.rating ASC, r.created_at DESC';
    }
    $rows = q_all('SELECT r.* FROM ' . t('reviews') . ' r ' . $where . ' ORDER BY ' . $order, $params);
    return [
        'items' => array_map(function ($r) use ($viewer) { return review_dto($r, $viewer); }, $rows),
    ];
}

function h_reviews_create()
{
    $user = require_verified();
    $courseId = param_str('id', param_str('courseId', param_str('course_id', '', 40), 40), 40);
    find_or_fail(t('courses'), $courseId, '课程不存在');

    $rating = param_int('rating', 0, 1, 5);
    $content = param_str('content', '', 4000);
    if (!$rating) {
        throw new ApiException('请先选择 1-5 星评分');
    }
    if (mb_strlen($content) < 5) {
        throw new ApiException('短评至少写 5 个字，方便别人参考');
    }
    if (q_val('SELECT COUNT(*) FROM ' . t('reviews') . ' WHERE course_id = ? AND user_id = ?', [$courseId, $user['id']])) {
        throw new ApiException('你已经评价过这门课了，可以编辑原来的评价', 409);
    }

    $id = new_id('r_');
    q(
        'INSERT INTO ' . t('reviews') . ' (id, course_id, user_id, rating, title, content, tags, take_again, anonymous, semester, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            $id, $courseId, $user['id'], $rating,
            param_str('title', '', 60), $content,
            json_encode(param_tags('tags', 5), JSON_UNESCAPED_UNICODE),
            param_bool('takeAgain') ? 1 : 0,
            param_bool('anonymous') ? 1 : 0,
            param_str('semester', '', 20),
            now_ms(), now_ms(),
        ]
    );
    return ['review' => review_dto(find_or_fail(t('reviews'), $id, '短评不存在'), $user)];
}

function h_reviews_update()
{
    $user = require_login();
    $id = param_str('id', '', 40);
    $row = find_or_fail(t('reviews'), $id, '短评不存在');
    if ($row['user_id'] !== $user['id'] && empty($user['is_admin'])) {
        throw new ApiException('只能修改自己的评价', 403);
    }

    $rating = param_int('rating', (int) $row['rating'], 1, 5);
    $content = param_str('content', $row['content'], 4000);
    if (mb_strlen($content) < 5) {
        throw new ApiException('短评至少写 5 个字');
    }
    q(
        'UPDATE ' . t('reviews') . ' SET rating = ?, title = ?, content = ?, tags = ?, take_again = ?, anonymous = ?, semester = ?, updated_at = ? WHERE id = ?',
        [
            $rating,
            param_str('title', $row['title'], 60),
            $content,
            json_encode(param_tags('tags', 5), JSON_UNESCAPED_UNICODE),
            param_bool('takeAgain') ? 1 : 0,
            param_bool('anonymous') ? 1 : 0,
            param_str('semester', $row['semester'], 20),
            now_ms(),
            $id,
        ]
    );
    return ['review' => review_dto(find_or_fail(t('reviews'), $id, '短评不存在'), $user)];
}

function h_reviews_delete()
{
    $user = require_login();
    $id = param_str('id', '', 40);
    $row = find_or_fail(t('reviews'), $id, '短评不存在');
    if ($row['user_id'] !== $user['id'] && empty($user['is_admin'])) {
        throw new ApiException('只能删除自己的评价', 403);
    }
    q('DELETE FROM ' . t('likes') . ' WHERE target_type = ? AND target_id = ?', ['review', $id]);
    q('DELETE FROM ' . t('reviews') . ' WHERE id = ?', [$id]);
    return ['ok' => true];
}

function h_reviews_like()
{
    $id = param_str('id', '', 40);
    find_or_fail(t('reviews'), $id, '短评不存在');
    return toggle_like('review', $id);
}
