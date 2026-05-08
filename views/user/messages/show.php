<?php
/**
 * جزئیات conversation
 */
?>

<div class="container mx-auto px-4 py-8">
    <div class="max-w-4xl mx-auto">
        <!-- Header with user info -->
        <div class="bg-white rounded-lg shadow-sm border p-6 mb-6">
            <div class="flex justify-between items-center">
                <div class="flex items-center gap-4">
                    <div class="w-16 h-16 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white text-2xl font-bold">
                        <?php echo substr($other_user['full_name'], 0, 1); ?>
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900"><?php echo e($other_user['full_name']); ?></h1>
                        <p class="text-gray-600 text-sm" id="typing-indicator"></p>
                    </div>
                </div>
                <div class="flex gap-2">
                    <button class="p-2 hover:bg-gray-100 rounded-lg" title="اطلاعات بیشتر">
                        <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </button>
                    <button class="p-2 hover:bg-gray-100 rounded-lg" title="سایر گزینه‌ها">
                        <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"></path>
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- Messages Container -->
        <div class="bg-white rounded-lg shadow-sm border mb-6 flex flex-col h-96">
            <!-- Message History -->
            <div class="flex-1 overflow-y-auto p-6 space-y-4" id="messages-container">
                <?php if (empty($messages)): ?>
                    <div class="text-center text-gray-500 py-12">
                        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <p class="mt-2">هیچ پیامی هنوز ارسال نشده</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($messages as $msg): ?>
                        <div class="message-group" data-message-id="<?php echo e($msg['id']); ?>">
                            <?php if ($msg['sender_id'] == current_user_id()): ?>
                                <!-- Sent Message -->
                                <div class="flex justify-end">
                                    <div class="bg-blue-500 text-white rounded-lg px-4 py-2 max-w-xs">
                                        <p class="text-sm"><?php echo e($msg['message']); ?></p>
                                        <div class="text-xs mt-1 opacity-70 flex justify-between items-center gap-2">
                                            <span><?php echo format_time($msg['created_at']); ?></span>
                                            <?php if ($msg['read_at']): ?>
                                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"></path></svg>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <!-- Received Message -->
                                <div class="flex justify-start">
                                    <div class="bg-gray-200 text-gray-900 rounded-lg px-4 py-2 max-w-xs">
                                        <p class="text-sm"><?php echo e($msg['message']); ?></p>
                                        <div class="text-xs mt-1 opacity-70">
                                            <?php echo format_time($msg['created_at']); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Reactions -->
                            <?php if (!empty($msg['reactions'])): ?>
                                <div class="flex gap-1 mt-1 flex-wrap">
                                    <?php foreach ($msg['reactions'] as $emoji => $count): ?>
                                        <span class="bg-gray-100 rounded-full px-2 py-1 text-sm cursor-pointer hover:bg-gray-200">
                                            <?php echo e($emoji); ?> <span class="text-xs"><?php echo e($count); ?></span>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Compose Area -->
            <div class="border-t p-4 bg-gray-50 rounded-b-lg">
                <form id="message-form" class="flex gap-2">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="recipient_id" value="<?php echo e($other_user['id']); ?>">
                    
                    <textarea name="message" id="message-input" placeholder="پیام خود را بنویسید..."
                              class="flex-1 border border-gray-300 rounded-lg px-3 py-2 resize-none h-10 focus:outline-none focus:ring-2 focus:ring-blue-500"
                              maxlength="5000"></textarea>
                    
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium">
                        ارسال
                    </button>
                </form>
                <div class="text-xs text-gray-500 mt-2 flex justify-between">
                    <span id="char-count">0 / 5000</span>
                    <div class="space-x-2">
                        <button type="button" class="text-blue-600 hover:text-blue-700" title="فایل الحاق کنید">
                            📎
                        </button>
                        <button type="button" class="text-blue-600 hover:text-blue-700" title="ایموجی">
                            😊
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const form = document.getElementById('message-form');
const messageInput = document.getElementById('message-input');
const messagesContainer = document.getElementById('messages-container');
const recipientId = document.querySelector('input[name="recipient_id"]').value;
let typingTimeout;

// Character counter
messageInput.addEventListener('input', (e) => {
    document.getElementById('char-count').textContent = e.target.value.length + ' / 5000';
    
    // Send typing indicator
    clearTimeout(typingTimeout);
    fetch('/messages/typing', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': document.querySelector('[name="_token"]').value
        },
        body: JSON.stringify({ recipient_id: recipientId, is_typing: true })
    });
    
    typingTimeout = setTimeout(() => {
        fetch('/messages/typing', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('[name="_token"]').value
            },
            body: JSON.stringify({ recipient_id: recipientId, is_typing: false })
        });
    }, 3000);
});

// Send message
form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const message = messageInput.value.trim();
    
    if (!message) return;
    
    try {
        const response = await fetch('/messages/send', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('[name="_token"]').value
            },
            body: JSON.stringify({
                recipient_id: recipientId,
                message: message,
                is_encrypted: false
            })
        });
        
        if (response.ok) {
            messageInput.value = '';
            document.getElementById('char-count').textContent = '0 / 5000';
            // Reload messages
            loadMessages();
        }
    } catch (error) {
        console.error('Error sending message:', error);
    }
});

// Load messages periodically
function loadMessages() {
    // This would typically be a real-time update with WebSocket
    // For now, a simple implementation
    location.reload();
}

// Typing indicator listener
setInterval(() => {
    fetch(`/messages/typing/users?recipient_id=${recipientId}`)
        .then(r => r.json())
        .then(data => {
            const indicator = document.getElementById('typing-indicator');
            if (data.typing_users > 0) {
                indicator.textContent = 'در حال تایپ...';
            } else {
                indicator.textContent = '';
            }
        });
}, 1000);

// Auto-scroll to bottom
messagesContainer.scrollTop = messagesContainer.scrollHeight;
</script>