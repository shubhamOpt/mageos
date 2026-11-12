define(['jquery', 'mage/cookies'], function ($) {
    'use strict';

    return function (config, element) {
        var $root = $(element),
            $panel = $root.find('.ai-assistant__panel'),
            $toggle = $root.find('.ai-assistant__toggle'),
            $messages = $root.find('.ai-assistant__messages'),
            $form = $root.find('.ai-assistant__form'),
            $input = $form.find('input[name="message"]'),
            $send = $form.find('.ai-assistant__send'),
            history = [],
            busy = false;

        function setOpen(open) {
            $panel.prop('hidden', !open);
            $toggle.attr('aria-expanded', open ? 'true' : 'false');
            if (open) {
                $input.trigger('focus');
            }
        }

        function addMessage(role, text) {
            // .text() never interprets HTML, so replies cannot inject markup.
            var $msg = $('<p>').addClass('ai-assistant__msg ai-assistant__msg--' + role).text(text);
            $messages.append($msg);
            $messages.scrollTop($messages[0].scrollHeight);
            return $msg;
        }

        function send(message) {
            message = $.trim(message);
            if (!message || busy) {
                return;
            }
            busy = true;
            $send.prop('disabled', true);
            addMessage('user', message);
            $input.val('');
            var $typing = addMessage('typing', '…');

            $.ajax({
                url: config.url,
                type: 'POST',
                dataType: 'json',
                data: {
                    message: message,
                    history: JSON.stringify(history),
                    form_key: $.mage.cookies.get('form_key')
                }
            }).done(function (response) {
                $typing.remove();
                addMessage('assistant', response.answer);
                history.push({role: 'user', text: message}, {role: 'assistant', text: response.answer});
            }).fail(function (xhr) {
                $typing.remove();
                addMessage('error', (xhr.responseJSON && xhr.responseJSON.error) || 'Something went wrong. Please try again.');
            }).always(function () {
                busy = false;
                $send.prop('disabled', false);
                $input.trigger('focus');
            });
        }

        $toggle.on('click', function () { setOpen($panel.prop('hidden')); });
        $root.find('.ai-assistant__close').on('click', function () { setOpen(false); });
        $root.on('click', '.ai-assistant__shortcut', function () { send($(this).data('message')); });
        $form.on('submit', function (event) {
            event.preventDefault();
            send($input.val());
        });
    };
});
