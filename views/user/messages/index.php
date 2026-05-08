<?php
/**
 * لیست conversations
 */
?>

<div class="container mx-auto px-4 py-8">
    <div class="max-w-6xl mx-auto">
        <!-- Header -->
        <div class="bg-white rounded-lg shadow-sm border p-6 mb-6">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">پیام‌های من</h1>
                    <p class="text-gray-600 mt-1">مدیریت پیام‌های مستقیم با دیگر کاربران</p>
                </div>
                <div class="flex items-center gap-2">
                    <span class="badge badge-info">
                        <?php echo e($unread_total); ?> خوانده نشده
                    </span>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Conversations List -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-lg shadow-sm border">
                    <div class="p-6 border-b border-gray-200">
                        <div class="flex gap-2">
                            <input type="text" id="search-conversations" placeholder="جستجو کاربران..."
                                   class="flex-1 border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>

                    <div class="divide-y divide-gray-200">
                        <?php if (empty($conversations)): ?>
                            <div class="p-8 text-center">
                                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <h3 class="mt-2 text-sm font-medium text-gray-900">هیچ پیامی ندارید</h3>
                                <p class="mt-1 text-sm text-gray-500">شما هنوز هیچ گفتگویی نداشته‌اید</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($conversations as $conv): ?>
                                <a href="/messages/<?php echo e($conv['user_id']); ?>" 
                                   class="block hover:bg-gray-50 transition p-4">
                                    <div class="flex items-center gap-4">
                                        <div class="w-12 h-12 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white font-bold">
                                            <?php echo e(substr($conv['user_name'], 0, 1)); ?>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex justify-between items-start">
                                                <h3 class="font-medium text-gray-900">
                                                    <?php echo e($conv['user_name']); ?>
                                                </h3>
                                                <span class="text-sm text-gray-500">
                                                    <?php echo format_time($conv['last_message_at']); ?>
                                                </span>
                                            </div>
                                            <p class="text-sm text-gray-600 truncate mt-1">
                                                <?php echo e(substr($conv['last_message'], 0, 100)); ?>
                                            </p>
                                        </div>
                                        <?php if ($conv['unread_count'] > 0): ?>
                                            <span class="badge badge-danger">
                                                <?php echo e($conv['unread_count']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="space-y-6">
                <!-- Quick Actions -->
                <div class="bg-white rounded-lg shadow-sm border p-6">
                    <h3 class="font-semibold text-gray-900 mb-4">اقدامات سریع</h3>
                    <div class="space-y-2">
                        <button class="w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium"
                                onclick="openNewMessageModal()">
                            پیام جدید
                        </button>
                        <button class="w-full bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg font-medium">
                            بلاک‌ لیست
                        </button>
                    </div>
                </div>

                <!-- Stats -->
                <div class="bg-white rounded-lg shadow-sm border p-6">
                    <h3 class="font-semibold text-gray-900 mb-4">آمار</h3>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-gray-600">کل conversations:</dt>
                            <dd class="font-semibold"><?php echo e(count($conversations)); ?></dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-600">خوانده نشده:</dt>
                            <dd class="font-semibold text-red-600"><?php echo e($unread_total); ?></dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="new-message-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black bg-opacity-50">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl p-6">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-semibold text-gray-900">ارسال پیام جدید</h2>
            <button onclick="closeNewMessageModal()" class="text-gray-500 hover:text-gray-900">بستن</button>
        </div>
        <form id="new-message-form" class="space-y-4">
            <?php echo csrf_field(); ?>
            <div>
                <label class="block text-sm font-medium text-gray-700">شناسه گیرنده یا نام کاربری</label>
                <input id="new-recipient" type="text" name="recipient_identifier" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="ID یا نام کاربری...">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">متن پیام</label>
                <textarea id="new-message-text" name="message" rows="4" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" maxlength="5000"></textarea>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" class="px-4 py-2 rounded-lg bg-gray-200 text-gray-900 hover:bg-gray-300" onclick="closeNewMessageModal()">لغو</button>
                <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700">ارسال</button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('search-conversations').addEventListener('input', function(e) {
    const searchTerm = e.target.value.toLowerCase();
    document.querySelectorAll('a[href^="/messages/"]').forEach(el => {
        const userName = el.querySelector('h3').textContent.toLowerCase();
        el.style.display = userName.includes(searchTerm) ? 'block' : 'none';
    });
});

function openNewMessageModal() {
    document.getElementById('new-message-modal').classList.remove('hidden');
}

function closeNewMessageModal() {
    document.getElementById('new-message-modal').classList.add('hidden');
}

document.getElementById('new-message-form').addEventListener('submit', async function(e) {
    e.preventDefault();

    const recipientIdentifier = document.getElementById('new-recipient').value.trim();
    const message = document.getElementById('new-message-text').value.trim();
    const token = document.querySelector('[name="_token"]').value;

    if (!recipientIdentifier || !message) {
        return;
    }

    // Simple routing by identifier: assume numeric ID or username
    let recipientId = recipientIdentifier;
    if (isNaN(recipientId)) {
        recipientId = 0;
    }

    const payload = {
        recipient_id: recipientId,
        message,
        is_encrypted: false
    };

    const response = await fetch('/messages/send', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': token
        },
        body: JSON.stringify(payload)
    });

    if (response.ok) {
        closeNewMessageModal();
        window.location.reload();
    }
});

// Real-time unread count update
setInterval(() => {
    fetch('/messages/unread/count')
        .then(r => r.json())
        .then(data => {
            document.querySelector('.badge-info').textContent = data.count + ' خوانده نشده';
        });
}, 5000);
</script>