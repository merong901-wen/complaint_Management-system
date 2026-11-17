(() => {
    const picker = document.querySelector("[data-chat-picker]");
    const pickerOpen = document.querySelector("[data-chat-picker-open]");
    const pickerClose = document.querySelector("[data-chat-picker-close]");
    const pickerSearch = document.querySelector("[data-chat-picker-search]");
    const pickerItems = Array.from(document.querySelectorAll("[data-picker-item]"));
    const pickerEmpty = document.querySelector("[data-picker-empty]");

    pickerOpen?.addEventListener("click", () => {
        if (picker instanceof HTMLDialogElement) {
            picker.showModal();
            pickerSearch?.focus();
        }
    });
    pickerClose?.addEventListener("click", () => picker?.close());
    picker?.addEventListener("click", (event) => {
        if (event.target === picker) picker.close();
    });
    pickerSearch?.addEventListener("input", () => {
        const query = pickerSearch.value.trim().toLocaleLowerCase();
        let visible = 0;
        pickerItems.forEach((item) => {
            const matches = item.dataset.contactName.toLocaleLowerCase().includes(query);
            item.hidden = !matches;
            if (matches) visible++;
        });
        if (pickerEmpty) {
            pickerEmpty.hidden = visible > 0;
            pickerEmpty.textContent = pickerItems.length
                ? "No permitted users match your search."
                : "No permitted users are currently available.";
        }
    });

    const search = document.querySelector("[data-chat-search]");
    const contactList = document.querySelector("[data-chat-contact-list]");
    const noResults = document.querySelector("[data-chat-no-results]");
    const contacts = Array.from(document.querySelectorAll("[data-chat-contact]"));

    if (search && contactList) {
        search.addEventListener("input", () => {
            const query = search.value.trim().toLocaleLowerCase();
            let visible = 0;
            contacts.forEach((contact) => {
                const matches = contact.dataset.contactName.toLocaleLowerCase().includes(query);
                contact.hidden = !matches;
                if (matches) visible++;
            });
            if (noResults) noResults.hidden = visible > 0;
        });
    }

    const messageList = document.querySelector(".chat-messages");
    if (messageList) messageList.scrollTop = 0;

    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    const statusUrl = "../includes/chat_presence.php";
    const currentUserId = document.querySelector("[data-chat-current-user]")?.dataset.chatCurrentUser;
    const selectedUserId = document.querySelector("[data-chat-selected-user]")?.dataset.chatSelectedUser;
    let renderedMessageIds = Array.from(messageList?.querySelectorAll("[data-message-id]") || [])
        .map((message) => message.dataset.messageId)
        .join(",");
    const renderMessages = (messages) => {
        if (!messageList) return;
        const messageIds = messages.map((message) => String(message.id)).join(",");
        if (messageIds === renderedMessageIds) return;
        renderedMessageIds = messageIds;
        messageList.replaceChildren();
        if (!messages.length) {
            const empty = document.createElement("div");
            empty.className = "empty-state";
            empty.textContent = "No messages yet. Start the conversation.";
            messageList.append(empty);
            return;
        }

        messages.forEach((message) => {
            const isMe = String(message.sender_id) === String(currentUserId);
            const bubble = document.createElement("article");
            bubble.className = `chat-bubble ${isMe ? "me" : "them"}`;
            bubble.dataset.messageId = String(message.id);

            const meta = document.createElement("small");
            const sender = isMe ? "You" : (message.full_name || message.username || "Contact");
            const sentAt = new Date(message.created_at);
            meta.textContent = `${sender} · ${Number.isNaN(sentAt.getTime()) ? "" : sentAt.toLocaleString()}`;
            bubble.append(meta);

            if (message.message_text) {
                const text = document.createElement("div");
                text.textContent = message.message_text;
                bubble.append(text);
            }
            if (message.attachment_path) {
                const attachment = document.createElement("a");
                attachment.className = "chat-attachment";
                attachment.href = `../includes/chat_download.php?id=${encodeURIComponent(message.id)}&type=attachment`;
                attachment.textContent = message.attachment_name || "Attachment";
                bubble.append(attachment);
            }
            if (message.voice_path) {
                const audio = document.createElement("audio");
                audio.controls = true;
                audio.className = "mt-2 w-100";
                const source = document.createElement("source");
                source.src = `../includes/chat_download.php?id=${encodeURIComponent(message.id)}&type=voice`;
                source.type = message.voice_type || "audio/webm";
                audio.append(source);
                audio.append(document.createTextNode("Your browser cannot play this audio."));
                bubble.append(audio);
            }
            messageList.append(bubble);
        });
        messageList.scrollTop = 0;
    };

    const updatePresence = async () => {
        if (!token) return;
        const body = new FormData();
        body.set("csrf_token", token);
        if (selectedUserId) body.set("selected_user", selectedUserId);
        try {
            const response = await fetch(statusUrl, {
                method: "POST",
                body,
                credentials: "same-origin",
                cache: "no-store",
            });
            if (!response.ok) return;
            const status = await response.json();
            const contactData = new Map((status.contacts || []).map((contact) => [String(contact.id), contact]));
            contacts.forEach((contact) => {
                const details = contactData.get(contact.dataset.presenceUser);
                if (!details) return;
                const isOnline = details.online;
                const dot = contact.querySelector("[data-presence-dot]");
                if (dot) {
                    dot.classList.toggle("online", isOnline);
                    dot.setAttribute("aria-label", isOnline ? "Online" : "Offline");
                }

                const preview = contact.querySelector(".chat-contact-preview");
                if (preview) preview.textContent = details.preview;
                const row = contact.querySelector(".chat-contact-row:last-child");
                let badge = contact.querySelector(".chat-unread");
                if (details.unread > 0) {
                    if (!badge) {
                        badge = document.createElement("span");
                        badge.className = "chat-unread";
                        row?.append(badge);
                    }
                    badge.textContent = String(details.unread);
                    badge.setAttribute("aria-label", `${details.unread} unread messages`);
                } else {
                    badge?.remove();
                }

                const timeRow = contact.querySelector(".chat-contact-row:first-child");
                let time = contact.querySelector(".chat-contact-time");
                if (details.last_message_at) {
                    const date = new Date(details.last_message_at);
                    if (!time && timeRow) {
                        time = document.createElement("time");
                        time.className = "chat-contact-time";
                        timeRow.append(time);
                    }
                    if (time) {
                        time.dateTime = details.last_message_at;
                        time.textContent = Number.isNaN(date.getTime())
                            ? ""
                            : new Intl.DateTimeFormat(undefined, { month: "short", day: "numeric" }).format(date);
                    }
                } else {
                    time?.remove();
                }
                contact.dataset.lastMessageAt = details.last_message_at || "";
            });

            if (contactList) {
                const sorted = contacts.slice().sort((first, second) => {
                    const firstTime = Date.parse(first.dataset.lastMessageAt || "") || 0;
                    const secondTime = Date.parse(second.dataset.lastMessageAt || "") || 0;
                    return secondTime - firstTime;
                });
                sorted.forEach((contact) => contactList.insertBefore(contact, noResults));
            }
            const selectedContact = contactData.get(String(selectedUserId));
            const selectedDot = document.querySelector("[data-selected-presence-dot]");
            const selectedText = document.querySelector("[data-selected-presence-text]");
            if (selectedContact && selectedDot && selectedText) {
                selectedDot.classList.toggle("online", selectedContact.online);
                selectedText.textContent = selectedContact.online ? "Online" : "Offline";
            }
            if (Array.isArray(status.messages)) renderMessages(status.messages);
        } catch {
            // Presence is best-effort; the chat remains usable if the status check fails.
        }
    };

    if (token) {
        updatePresence();
        window.setInterval(updatePresence, 30000);
    }

    const startButton = document.querySelector("[data-record-start]");
    const stopButton = document.querySelector("[data-record-stop]");
    const status = document.querySelector("[data-recording-status]");
    const composer = document.querySelector("[data-chat-composer]");
    const voiceInput = composer?.querySelector('input[name="voice_message"]');
    let recorder;
    let recordingStream;
    let audioChunks = [];

    if (startButton && stopButton && status && composer && voiceInput) {
        startButton.addEventListener("click", async () => {
            if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
                status.textContent = "Voice recording is not supported by this browser.";
                return;
            }

            try {
                recordingStream = await navigator.mediaDevices.getUserMedia({ audio: true });
                const supportedTypes = ["audio/webm;codecs=opus", "audio/webm", "audio/ogg;codecs=opus", "audio/ogg", "audio/mp4"];
                const mimeType = supportedTypes.find((type) => MediaRecorder.isTypeSupported(type));
                recorder = mimeType ? new MediaRecorder(recordingStream, { mimeType }) : new MediaRecorder(recordingStream);
                audioChunks = [];
                recorder.addEventListener("dataavailable", (event) => {
                    if (event.data.size) audioChunks.push(event.data);
                });
                recorder.addEventListener("stop", () => {
                    const blob = new Blob(audioChunks, { type: recorder.mimeType || "audio/webm" });
                    const extension = blob.type.includes("ogg") ? "ogg" : (blob.type.includes("mp4") ? "m4a" : "webm");
                    const file = new File([blob], `voice-message.${extension}`, { type: blob.type });
                    const transfer = new DataTransfer();
                    transfer.items.add(file);
                    voiceInput.files = transfer.files;
                    status.textContent = "Voice message ready. Send it with the button.";
                    recordingStream?.getTracks().forEach((track) => track.stop());
                    recordingStream = undefined;
                    startButton.hidden = false;
                    stopButton.hidden = true;
                }, { once: true });
                recorder.start();
                startButton.hidden = true;
                stopButton.hidden = false;
                status.textContent = "Recording…";
            } catch {
                recordingStream?.getTracks().forEach((track) => track.stop());
                recordingStream = undefined;
                status.textContent = "Microphone access was denied or unavailable.";
            }
        });

        stopButton.addEventListener("click", () => {
            if (recorder?.state === "recording") recorder.stop();
        });

        voiceInput.addEventListener("change", () => {
            if (voiceInput.files?.length) status.textContent = `Audio selected: ${voiceInput.files[0].name}`;
        });

        composer.addEventListener("submit", (event) => {
            if (recorder?.state === "recording") {
                event.preventDefault();
                status.textContent = "Stop the recording before sending.";
                return;
            }
            const hasMessage = composer.querySelector("[data-chat-message]")?.value.trim();
            const hasAttachment = composer.querySelector('input[name="attachment"]')?.files?.length;
            const hasVoice = voiceInput.files?.length;
            if (!hasMessage && !hasAttachment && !hasVoice) {
                event.preventDefault();
                status.textContent = "Write a message, attach a file, or record a voice message.";
            }
        });
    }
})();
