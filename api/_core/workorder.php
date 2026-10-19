<?php

function workorder_form_catalog(): array
{
    $forms = [
        ['id'=>91,'workOrderTypeId'=>4,'workOrderTypeName'=>'存款未到账自动化','displayName'=>'Deposit Not Received ','sort'=>20,'icon'=>'/images/support-icons/deposit.png','type'=>1,'outLink'=>''],
        ['id'=>89,'workOrderTypeId'=>5,'workOrderTypeName'=>'取款未到账','displayName'=>'Withdrawal problem ','sort'=>19,'icon'=>'/images/support-icons/withdrawal.png','type'=>1,'outLink'=>''],
        ['id'=>90,'workOrderTypeId'=>11,'workOrderTypeName'=>'修改ISFC自动化','displayName'=>'IFSC Modification','sort'=>18,'icon'=>'/images/support-icons/ifsc.png','type'=>1,'outLink'=>''],
        ['id'=>97,'workOrderTypeId'=>12,'workOrderTypeName'=>'修改银行名称自动化','displayName'=>'Change bank name','sort'=>17,'icon'=>'/images/support-icons/bank.png','type'=>1,'outLink'=>''],
        ['id'=>94,'workOrderTypeId'=>8,'workOrderTypeName'=>'修改登录密码半自动','displayName'=>'Change ID Login Password','sort'=>15,'icon'=>'/images/support-icons/password.png','type'=>1,'outLink'=>''],
        ['id'=>100,'workOrderTypeId'=>3,'workOrderTypeName'=>'其他问题','displayName'=>'WINGO WIN STREAK BONUS','sort'=>10,'icon'=>'/images/support-icons/wingo.png','type'=>1,'outLink'=>''],
        ['id'=>92,'workOrderTypeId'=>2,'workOrderTypeName'=>'一对一客服','displayName'=>'Game Problems','sort'=>9,'icon'=>'/images/support-icons/game.png','type'=>1,'outLink'=>''],
        ['id'=>98,'workOrderTypeId'=>1,'workOrderTypeName'=>'外部链接','displayName'=>'Online customer service','sort'=>8,'icon'=>'/images/support-icons/support.png','type'=>1,'outLink'=>''],
        ['id'=>102,'workOrderTypeId'=>3,'workOrderTypeName'=>'其他问题','displayName'=>'Change UPI account','sort'=>7,'icon'=>'/images/support-icons/upi.png','type'=>1,'outLink'=>''],
        ['id'=>99,'workOrderTypeId'=>17,'workOrderTypeName'=>'新增USDT半自动','displayName'=>'Add a new USDT address','sort'=>6,'icon'=>'/images/support-icons/usdt.png','type'=>1,'outLink'=>''],
    ];

    $visuals = json_decode((string)@file_get_contents(__DIR__ . '/reference-icons.json'), true);
    foreach ($forms as &$form) {
        foreach (($visuals['forms'] ?? []) as $reference) {
            if ((int)$reference['workOrderTypeId'] === (int)$form['workOrderTypeId']) {
                $form['icon'] = $reference['icon'];
                break;
            }
        }
        $form['iconUrl'] = $form['icon'];
        $form['imgUrl'] = $form['icon'];
        $form['image'] = $form['icon'];
        $form['name'] = trim((string)$form['displayName']);
        $form['title'] = $form['name'];
        $form['status'] = 1;
    }
    unset($form);
    return $forms;
}

function workorder_home_payload(array $settings): array
{
    return [
        'isOpen' => (bool)($settings['workorder_enabled'] ?? true),
        'serviceUrl' => '/workOrder',
        'selfServiceUrl' => '/workOrder',
        'progressQueryUrl' => '/workOrder/record',
        'bannerUrl' => '/img/6016/other/051109483-141142-file_20260919171109440.webp',
        'isEnabledFaq' => true,
        'hasFaq' => true,
        'faqIcon' => '/img/6016/other/062508125-133733-file_20260912182508032.webp',
        'tips' => 'Please select the relevant query and submit it for review. After successful submission, admin reply will show in Progress Query.',
        'kindTipsText' => "Please select the corresponding question and submit it for review. After successful submission, the customer service specialist will handle it for you immediately. Please wait patiently.\nAfter submitting for review, you can use [Progress Query] to view the review results of the work order you submitted.",
        'hasDepositForm' => true,
        'hasRechargeForm' => true,
    ];
}

function workorder_form_type_map(): array
{
    return [
        91=>4, 89=>5, 90=>11, 97=>12, 94=>8, 100=>3, 92=>2, 98=>1, 102=>3, 99=>17,
        121=>4, 122=>5, 124=>11, 123=>18, 156=>14, 154=>16, 157=>20, 155=>13,
        160=>19, 128=>8, 125=>12, 131=>1, 148=>2,
    ];
}

function workorder_type_id_from_row(array $row): int
{
    $storedType = (int)($row['work_order_type_id'] ?? 0);
    if ($storedType > 0) return $storedType;
    $formId = (int)($row['form_id'] ?? 0);
    return workorder_form_type_map()[$formId] ?? $formId;
}

function workorder_public_row(array $row): array
{
    $rawStatus = strtolower((string)($row['status'] ?? 'Pending'));
    $statusMap = [
        'pending'=>1, 'wait'=>1, 'processing'=>2, 'pendingreview'=>2, 'review'=>2,
        'replied'=>3, 'answered'=>3, 'completed'=>3, 'success'=>3, 'closed'=>3,
        'rejected'=>4, 'reject'=>4, 'failed'=>4,
    ];
    $statusNo = $statusMap[$rawStatus] ?? 1;
    $createdMs = strtotime((string)($row['created_at'] ?? 'now')) * 1000;
    $updatedMs = !empty($row['updated_at']) ? strtotime((string)$row['updated_at']) * 1000 : 0;
    $content = (string)($row['content'] ?? '');
    $adminReply = trim((string)($row['admin_reply'] ?? ''));
    $remark = $content;
    if ($adminReply !== '') $remark .= ($remark !== '' ? "\n\n" : '') . 'Admin reply: ' . $adminReply;
    $title = (string)($row['title'] ?? 'Support Query');
    $typeName = (string)($row['type_name'] ?? '') ?: $title;
    $imageUrl = (string)($row['image_url'] ?? '');
    $ticketNo = (string)($row['ticket_no'] ?? '');
    return [
        'id'=>(int)($row['id'] ?? 0), 'orderId'=>(int)($row['id'] ?? 0),
        'workOrderNo'=>$ticketNo, 'ticketNo'=>$ticketNo, 'orderNo'=>$ticketNo,
        'userId'=>(int)($row['user_id'] ?? 0),
        'workOrderTypeName'=>$typeName, 'workOrderTypeId'=>workorder_type_id_from_row($row),
        'title'=>$title, 'typeName'=>$typeName, 'remark'=>$remark, 'content'=>$content,
        'remarkImageUri'=>$imageUrl, 'attachmentPath'=>$imageUrl,
        'status'=>$statusNo, 'state'=>(string)($row['status'] ?? 'Pending'),
        'statusText'=>(string)($row['status'] ?? 'Pending'), 'adminReply'=>$adminReply,
        'isBtnDisabled'=>false, 'submissionTime'=>$createdMs, 'createTime'=>$createdMs,
        'lastUpdateTime'=>$updatedMs, 'updateTime'=>$updatedMs,
    ];
}

function workorder_comment_rows(array $row): array
{
    $createdMs = strtotime((string)($row['created_at'] ?? 'now')) * 1000;
    $updatedMs = !empty($row['updated_at']) ? strtotime((string)$row['updated_at']) * 1000 : $createdMs;
    $content = (string)($row['content'] ?? '');
    $imageUrl = (string)($row['image_url'] ?? '');
    $list = [[
        'commentContent'=>$content, 'content'=>$content, 'type'=>0, 'userType'=>1,
        'commentAt'=>$createdMs, 'createTime'=>$createdMs, 'attachmentPath'=>$imageUrl,
    ]];
    $adminReply = trim((string)($row['admin_reply'] ?? ''));
    if ($adminReply !== '') {
        $list[] = [
            'commentContent'=>$adminReply, 'content'=>$adminReply, 'type'=>1, 'userType'=>2,
            'commentAt'=>$updatedMs, 'createTime'=>$updatedMs, 'attachmentPath'=>'',
        ];
    }
    return $list;
}

function workorder_required_columns(): array
{
    return [
        'user_id' => "ALTER TABLE work_orders ADD COLUMN user_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER id",
        'ticket_no' => "ALTER TABLE work_orders ADD COLUMN ticket_no VARCHAR(80) NOT NULL DEFAULT ''",
        'form_id' => "ALTER TABLE work_orders ADD COLUMN form_id BIGINT DEFAULT 0",
        'work_order_type_id' => "ALTER TABLE work_orders ADD COLUMN work_order_type_id BIGINT DEFAULT 0 AFTER form_id",
        'type_name' => "ALTER TABLE work_orders ADD COLUMN type_name VARCHAR(120) DEFAULT ''",
        'title' => "ALTER TABLE work_orders ADD COLUMN title VARCHAR(190) DEFAULT ''",
        'content' => "ALTER TABLE work_orders ADD COLUMN content TEXT",
        'contact' => "ALTER TABLE work_orders ADD COLUMN contact VARCHAR(190) DEFAULT ''",
        'image_url' => "ALTER TABLE work_orders ADD COLUMN image_url VARCHAR(500) DEFAULT ''",
        'status' => "ALTER TABLE work_orders ADD COLUMN status ENUM('Pending','Processing','Answered','Closed','Rejected') NOT NULL DEFAULT 'Pending'",
        'admin_reply' => "ALTER TABLE work_orders ADD COLUMN admin_reply TEXT",
        'created_at' => "ALTER TABLE work_orders ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "ALTER TABLE work_orders ADD COLUMN updated_at DATETIME DEFAULT NULL",
    ];
}

function workorder_execute_insert(object $statement): void
{
    if (!$statement->execute()) {
        $detail = trim((string)($statement->error ?? ''));
        throw new RuntimeException('Support ticket could not be saved' . ($detail !== '' ? ': ' . $detail : '.'));
    }
}
