(function() {
    const API = 'api.php';
    const POLL_INTERVAL = 3000;

    const $ = id => document.getElementById(id);
    const sidebar = $('sidebar'), chatMain = $('chatMain'), contactsList = $('contactsList');
    const messagesArea = $('messagesArea'), messageInput = $('messageInput'), sendBtn = $('sendBtn');
    const backBtn = $('backBtn'), themeToggle = $('themeToggle'), searchInput = $('searchInput');
    const chatHeaderName = $('chatHeaderName'), chatHeaderStatus = $('chatHeaderStatus'), chatAvatar = $('chatAvatar');
    const filterTabs = document.querySelectorAll('.filter-tab');
    const newChatBtn = $('newChatBtn'), logoutBtn = $('logoutBtn');
    const newChatModal = $('newChatModal'), modalClose = $('modalClose');
    const userSearchInput = $('userSearchInput'), userSearchResults = $('userSearchResults');

    let activeContactId = null, activeContactData = null, contacts = [];
    let isMobile = window.innerWidth <= 768, currentFilter = 'all', pollTimer = null;
    let contextMenu = null, emojiPicker = null, currentTab = 'chats';

    // === Utils ===
    function esc(s) { if(!s)return''; const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
    function fmtSize(b) { if(b<1024)return b+' B'; if(b<1048576)return(b/1024).toFixed(1)+' KB'; return(b/1048576).toFixed(1)+' MB'; }
    function avHtml(letter, color, url, cls='avatar') {
        return '<div class="'+cls+'" style="background:'+esc(color)+';">'+esc(letter)+'</div>';
    }
    function resizeImage(file, maxW, maxH) {
        return new Promise((resolve) => {
            const reader = new FileReader();
            reader.onload = (e) => {
                const img = new Image();
                img.onload = () => {
                    const canvas = document.createElement('canvas');
                    let w = img.width, h = img.height;
                    // Center crop to square
                    const size = Math.min(w, h);
                    const sx = (w - size) / 2, sy = (h - size) / 2;
                    canvas.width = maxW; canvas.height = maxH;
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(img, sx, sy, size, size, 0, 0, maxW, maxH);
                    canvas.toBlob((blob) => resolve(blob), 'image/jpeg', 0.85);
                };
                img.src = e.target.result;
            };
            reader.readAsDataURL(file);
        });
    }

    async function api(action, params={}) {
        const u = new URL(API, location.origin+location.pathname.replace(/\/[^\/]*$/,'/'));
        u.searchParams.set('action', action);
        for(const[k,v]of Object.entries(params)) if(v!=null) u.searchParams.set(k,v);
        try{const r=await fetch(u,{credentials:'same-origin'});if(r.status===401){location.href='login.php';return null;}return r.json();}catch(e){return null;}
    }
    async function apiPost(action, body={}) {
        try{const r=await fetch(API+'?action='+action,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify(body)});
        if(r.status===401){location.href='login.php';return null;}return r.json();}catch(e){return null;}
    }
    async function apiUpload(action, formData) {
        try{const r=await fetch(API+'?action='+action,{method:'POST',credentials:'same-origin',body:formData});
        if(r.status===401){location.href='login.php';return null;}return r.json();}catch(e){return null;}
    }
    function customConfirm(msg){return new Promise(resolve=>{const m=createModal('确认','<p style="text-align:center;color:var(--text-secondary);margin-bottom:20px">'+msg+'</p><div class="modal-btn-row"><button class="btn-secondary" id="cCancel">取消</button><button class="btn-danger-outline" id="cOk">确定</button></div>');m.querySelector('#cCancel').addEventListener('click',()=>{m.remove();resolve(false);});m.querySelector('#cOk').addEventListener('click',()=>{m.remove();resolve(true);});});}
    function createModal(title, html, opts={}) {
        const m=document.createElement('div');m.className='modal-overlay';m.style.display='flex';
        const cls=opts.wide?'modal-box modal-wide':'modal-box';
        m.innerHTML='<div class="'+cls+'"><div class="modal-header"><h3>'+title+'</h3><button class="modal-close">&times;</button></div>'+html+'</div>';
        document.body.appendChild(m);
        m.querySelector('.modal-close').addEventListener('click',()=>m.remove());
        m.addEventListener('click',e=>{if(e.target===m)m.remove();});
        return m;
    }

    // === Theme ===
    const savedTheme=localStorage.getItem('chat-theme')||document.body.getAttribute('data-theme')||'light';
    document.body.setAttribute('data-theme',savedTheme);
    themeToggle.addEventListener('click',()=>{
        const next=document.body.getAttribute('data-theme')==='light'?'dark':'light';
        document.body.setAttribute('data-theme',next);localStorage.setItem('chat-theme',next);
        themeToggle.style.transform='rotate(360deg)';setTimeout(()=>{themeToggle.style.transform='';},400);
        apiPost('update_profile',{theme:next});
    });
    function checkMobile(){isMobile=window.innerWidth<=768;if(!isMobile){sidebar.classList.remove('hidden-on-mobile');chatMain.classList.remove('hidden-on-mobile');}}
    window.addEventListener('resize',checkMobile);

    // ====== FRIENDS LIST (shown when filter=friends) ======
    let tabContentEl=null;
    function getTabContentEl(){
        if(!tabContentEl){
            tabContentEl=document.createElement('div');tabContentEl.className='tab-content';
            contactsList.parentNode.insertBefore(tabContentEl,contactsList.nextSibling);
        }
        return tabContentEl;
    }

    async function loadFriendsList(){
        const el=getTabContentEl();el.style.display='block';el.innerHTML='<div class="loading-msg">加载中...</div>';
        contactsList.style.display='none';
        const fd=await api('friends');
        const friends=fd?.friends||[];
        let h='<div class="tab-header"><span class="tab-count">'+friends.length+' 位好友</span></div>';
        if(!friends.length)h+='<div class="tab-empty">还没有好友，搜索用户添加吧</div>';
        else h+=friends.map(f=>'<div class="friend-card" data-uid="'+f.id+'">'+avHtml(f.avatar,f.avatar_color,f.avatar_url,'avatar avatar-sm')+'<div class="friend-info"><span class="friend-name">'+esc(f.nickname)+'</span><span class="friend-user">@'+esc(f.username)+'</span></div>'+(f.online?'<span class="online-dot" style="width:8px;height:8px;"></span>':'')+'<div class="friend-actions"><button class="btn-chat" data-uid="'+f.id+'">💬</button><button class="btn-remove" data-uid="'+f.id+'">🗑️</button></div></div>').join('');
        el.innerHTML=h;
        el.querySelectorAll('.btn-chat').forEach(b=>{b.addEventListener('click',async()=>{const r=await apiPost('create_conversation',{user_id:+b.getAttribute('data-uid')});if(r?.conversation_id){await loadContacts();switchContact(r.conversation_id);showChatsList();}});});
        el.querySelectorAll('.btn-remove').forEach(b=>{b.addEventListener('click',async()=>{if(!await customConfirm('确定删除该好友？'))return;await apiPost('remove_friend',{user_id:+b.getAttribute('data-uid')});loadFriendsList();});});
        el.querySelectorAll('.friend-card').forEach(card=>{card.addEventListener('click',e=>{if(e.target.closest('.friend-actions'))return;showUserProfile(+card.getAttribute('data-uid'));});});
    }

    function showChatsList(){
        contactsList.style.display='';
        if(tabContentEl)tabContentEl.style.display='none';
    }

    // ====== REQUESTS MODAL (from + button) ======
    async function showRequestsModal(){
        const rd=await api('friend_requests');const requests=rd?.requests||[];
        let body='';
        if(!requests.length)body='<div class="tab-empty">暂无待处理的好友申请</div>';
        else body=requests.map(r=>'<div class="friend-card">'+avHtml(r.avatar,r.avatar_color,r.avatar_url,'avatar avatar-sm')+'<div class="friend-info"><span class="friend-name">'+esc(r.nickname)+'</span><span class="friend-user">@'+esc(r.username)+' · '+esc(r.time)+'</span></div><div class="friend-actions"><button class="btn-accept" data-uid="'+r.id+'">✓</button><button class="btn-decline" data-uid="'+r.id+'">✗</button></div></div>').join('');
        const m=createModal('好友申请 ('+requests.length+')',body);
        m.querySelectorAll('.btn-accept').forEach(b=>{b.addEventListener('click',async()=>{await apiPost('handle_friend_request',{user_id:+b.getAttribute('data-uid'),action2:'accept'});m.remove();showRequestsModal();});});
        m.querySelectorAll('.btn-decline').forEach(b=>{b.addEventListener('click',async()=>{await apiPost('handle_friend_request',{user_id:+b.getAttribute('data-uid'),action2:'decline'});m.remove();if(requests.length>1)showRequestsModal();});});
    }

    // + button dropdown menu
    let plusMenu=null;
    newChatBtn.addEventListener('click',(e)=>{
        e.stopPropagation();
        if(plusMenu){plusMenu.remove();plusMenu=null;return;}
        plusMenu=document.createElement('div');plusMenu.className='plus-dropdown';
        const rd=api('friend_requests').then(rd=>{
            const count=rd?.requests?.length||0;
            plusMenu.innerHTML='<div class="plus-item" id="pmAddFriend">👤 添加好友</div>'
                +'<div class="plus-item" id="pmCreateGroup">👥 创建群组</div>'
                +'<div class="plus-item" id="pmRequests">🔔 好友申请'+(count?'<span class="plus-badge">'+count+'</span>':'')+'</div>';
            // Position
            const rect=newChatBtn.getBoundingClientRect();
            plusMenu.style.top=(rect.bottom+4)+'px';plusMenu.style.right=(window.innerWidth-rect.right)+'px';
            document.body.appendChild(plusMenu);
            plusMenu.querySelector('#pmAddFriend').addEventListener('click',()=>{plusMenu.remove();plusMenu=null;openAddFriendModal();});
            plusMenu.querySelector('#pmCreateGroup').addEventListener('click',()=>{plusMenu.remove();plusMenu=null;openCreateGroupModal();});
            plusMenu.querySelector('#pmRequests').addEventListener('click',()=>{plusMenu.remove();plusMenu=null;showRequestsModal();});
            setTimeout(()=>document.addEventListener('click',()=>{if(plusMenu){plusMenu.remove();plusMenu=null;}},{once:true}),10);
        });
    });
    function openAddFriendModal(){
        isGroupMode=false;selectedMembers=[];
        newChatModal.style.display='flex';
        newChatModal.querySelector('.modal-header h3').textContent='添加好友';
        userSearchInput.value='';userSearchResults.innerHTML='';
        const ft=newChatModal.querySelector('.modal-footer');if(ft)ft.innerHTML='';
        userSearchInput.focus();
    }
    function openCreateGroupModal(){
        isGroupMode=true;selectedMembers=[];
        newChatModal.style.display='flex';
        newChatModal.querySelector('.modal-header h3').textContent='创建群组 - 搜索添加成员';
        userSearchInput.value='';userSearchResults.innerHTML='';
        updateModalUI();
        userSearchInput.focus();
    }
    function openNewChatModal(){isGroupMode=false;selectedMembers=[];newChatModal.style.display='flex';userSearchInput.value='';userSearchResults.innerHTML='';updateModalUI();userSearchInput.focus();}

    // Request badge poll
    async function updateRequestBadge(){
        const rd=await api('friend_requests');const count=rd?.requests?.length||0;
        const btn=newChatBtn;let badge=btn.querySelector('.req-badge');
        if(count>0){if(!badge){badge=document.createElement('span');badge.className='req-badge';btn.appendChild(badge);}badge.textContent=count;}
        else if(badge){badge.remove();}
    }

    // ====== CONTACTS (diff-based) ======
    async function loadContacts(){
        if(currentTab!=='chats')return;
        const data=await api('contacts',{filter:currentFilter,search:searchInput.value.trim()});
        if(!data?.contacts)return;
        if(JSON.stringify(contacts)!==JSON.stringify(data.contacts)){contacts=data.contacts;renderContacts();}
    }
    function renderContacts(){
        if(!contacts.length){contactsList.innerHTML='<div class="contacts-empty">暂无对话<br><small>点击右上角 + 开始新对话</small></div>';return;}
        const pinned=contacts.filter(c=>c.pinned),normal=contacts.filter(c=>!c.pinned);
        let h='';
        if(pinned.length){h+='<div class="section-label">📌 置顶</div>';pinned.forEach(c=>{h+=contactCard(c);});}
        const label=currentFilter==='groups'?'💬 全部群组':'💬 对话';
        if(pinned.length&&normal.length)h+='<div class="section-label">'+label+'</div>';
        normal.forEach(c=>{h+=contactCard(c);});
        contactsList.innerHTML=h;bindCardEvents();
    }
    function contactCard(c){
        let preview='';
        if(c.is_typing)preview='<span style="color:var(--typing-color);font-weight:500;">正在输入</span><span class="typing-indicator-mini"><span></span><span></span><span></span></span>';
        else{const m=c.last_message||'';preview='<span>'+esc(m.length>35?m.substring(0,35)+'...':m)+'</span>';}
        const pin=c.pinned?'<svg class="pin-icon-mini" viewBox="0 0 24 24" fill="currentColor"><path d="M16 12V4h1V2H7v2h1v8l-2 2v2h5.2v6h1.6v-6H18v-2l-2-2z"/></svg>':'';
        const av=avHtml(c.avatar,c.avatar_color,null,'avatar avatar-sm');
        return '<div class="contact-card'+(c.id===activeContactId?' active':'')+(c.pinned?' pinned':'')+'" data-cid="'+c.id+'" data-uid="'+(c.user_id||'')+'" data-pinned="'+(c.pinned?1:0)+'" data-group="'+(c.is_group?1:0)+'">'
            +'<div class="contact-avatar-wrap">'+av+(c.online?'<span class="online-dot" style="width:10px;height:10px;border-width:2px;"></span>':'')+'</div>'
            +'<div class="contact-info"><div class="contact-name-row"><span class="contact-name">'+esc(c.name)+'</span><span class="contact-time">'+esc(c.time||'')+'</span></div>'
            +'<div class="contact-preview">'+pin+preview+'</div></div>'
            +(c.unread?'<span class="unread-badge">'+c.unread+'</span>':'')
            +(c.is_group?'<span class="group-badge">'+c.member_count+'人</span>':'')+'</div>';
    }
    function bindCardEvents(){
        contactsList.querySelectorAll('.contact-card').forEach(card=>{
            card.addEventListener('click',function(e){if(e.target.closest('.context-menu'))return;const id=+this.getAttribute('data-cid');if(id===activeContactId&&!isMobile)return;switchContact(id,this);});
            let pt=null;
            card.addEventListener('contextmenu',function(e){e.preventDefault();showCtx(e,this);});
            card.addEventListener('touchstart',function(e){pt=setTimeout(()=>showCtx(e.touches[0],this),500);});
            card.addEventListener('touchend',()=>clearTimeout(pt));
            card.addEventListener('touchmove',()=>clearTimeout(pt));
        });
    }
    function showCtx(e,card){removeCtx();const id=+card.getAttribute('data-cid');const pinned=card.getAttribute('data-pinned')==='1';const isGroup=card.getAttribute('data-group')==='1';
        const menu=document.createElement('div');menu.className='context-menu';
        menu.innerHTML='<div class="context-item" data-a="pin">'+(pinned?'📌 取消置顶':'📌 置顶对话')+'</div>'
            +(isGroup?'<div class="context-item" data-a="members">👥 查看成员</div><div class="context-item" data-a="addmember">➕ 添加成员</div>':'')
            +'<div class="context-item danger" data-a="delete">🗑️ 删除对话</div>';
        const x=Math.min(e.clientX||e.pageX,innerWidth-160),y=Math.min(e.clientY||e.pageY,innerHeight-150);
        menu.style.left=x+'px';menu.style.top=y+'px';document.body.appendChild(menu);contextMenu=menu;
        menu.querySelectorAll('.context-item').forEach(item=>{item.addEventListener('click',async()=>{const a=item.getAttribute('data-a');
            if(a==='pin'){await apiPost('toggle_pin',{conversation_id:id});await loadContacts();}
            else if(a==='members'){showGroupMembers(id);}
            else if(a==='addmember'){showAddMember(id);}
            else if(a==='delete'){
                const dm=createModal('删除对话','<p style="text-align:center;color:var(--text-secondary);margin-bottom:20px">确定删除此对话？</p><div class="modal-btn-row"><button class="btn-secondary" id="cancelDel">取消</button><button class="btn-danger-outline" id="confirmDel">删除</button></div>');
                dm.querySelector('#cancelDel').addEventListener('click',()=>dm.remove());
                dm.querySelector('#confirmDel').addEventListener('click',async()=>{
                    dm.remove();
                    await apiPost('delete_conversation',{conversation_id:id});
                    if(activeContactId===id){activeContactId=null;messagesArea.innerHTML='<div class="empty-state"><div class="empty-icon">💬</div><h3>选择对话</h3></div>';}
                    await loadContacts();
                });
            }
            removeCtx();});});
        setTimeout(()=>document.addEventListener('click',removeCtx,{once:true}),10);
    }
    function removeCtx(){if(contextMenu){contextMenu.remove();contextMenu=null;}}

    // ====== SWITCH CONTACT ======
    async function switchContact(convId,cardEl){
        activeContactId=convId;
        contactsList.querySelectorAll('.contact-card').forEach(c=>c.classList.remove('active'));
        if(cardEl)cardEl.classList.add('active');
        activeContactData=contacts.find(c=>c.id===convId);
        if(activeContactData){
            chatHeaderName.textContent=activeContactData.name;
            const isGroup=activeContactData.is_group;
            chatAvatar.style.background=activeContactData.avatar_color;chatAvatar.innerHTML=esc(activeContactData.avatar);
            const dot=chatAvatar.parentElement.querySelector('.online-dot');if(dot)dot.remove();
            if(activeContactData.online){chatHeaderStatus.textContent='在线';chatHeaderStatus.classList.add('online');
                const d=document.createElement('span');d.className='online-dot';d.style.cssText='width:10px;height:10px;border-width:2px;';chatAvatar.parentElement.appendChild(d);
            }else{chatHeaderStatus.textContent=isGroup?(activeContactData.member_count+' 名成员'):'离线';chatHeaderStatus.classList.remove('online');}
            // FIX: use user_id for direct chats, conversation id for groups
            chatAvatar.style.cursor='pointer';
            chatAvatar.onclick=()=>{
                if(isGroup)showGroupMembers(activeContactData.id);
                else if(activeContactData.user_id)showUserProfile(activeContactData.user_id);
            };
        }
        await loadMessages(convId);
        if(isMobile){sidebar.classList.add('hidden-on-mobile');chatMain.classList.remove('hidden-on-mobile');}
        if(cardEl){const b=cardEl.querySelector('.unread-badge');if(b)b.remove();}
    }

    // ====== MESSAGES ======
    async function loadMessages(convId){
        messagesArea.innerHTML='<div class="loading-msg">加载中...</div>';
        const data=await api('messages',{conversation_id:convId});
        if(!data)return;messagesArea.innerHTML='';
        if(!data.messages||!data.messages.length){messagesArea.innerHTML='<div class="empty-state"><div class="empty-icon">💬</div><h3>开始对话</h3><p>发送第一条消息来开始愉快的交流</p></div>';return;}
        renderMessages(data.messages);
    }
    function renderMessages(msgs){
        const isGroup=activeContactData?.is_group;
        let lastD='';
        msgs.forEach((msg,i)=>{
            const isSent=msg.from===window.__CHAT_DATA__.currentUserId;
            if(msg.date!==lastD){const dv=document.createElement('div');dv.className='date-divider';dv.innerHTML='<span>'+esc(msg.date)+'</span>';messagesArea.appendChild(dv);lastD=msg.date;}
            const w=document.createElement('div');w.className='message-wrapper '+(isSent?'sent':'received');w.style.animationDelay=(i*0.04)+'s';
            let senderInfo='';
            if(isGroup&&!isSent){
                const sav=avHtml(msg.sender_avatar,msg.sender_avatar_color,null,'avatar avatar-xs');
                senderInfo='<div class="msg-sender">'+sav+'<span class="msg-sender-name">'+esc(msg.sender_name)+'</span></div>';
            }
            let bc='';
            if(msg.type==='image'&&msg.file_url)bc=senderInfo+'<div class="message-image"><img src="'+esc(msg.file_url)+'" loading="lazy" onclick="window.open(this.src)"></div>';
            else if(msg.type==='video'&&msg.file_url)bc=senderInfo+'<div class="message-video"><video controls src="'+esc(msg.file_url)+'"></video></div>';
            else if(msg.type==='audio'&&msg.file_url)bc=senderInfo+'<div class="message-audio"><audio controls src="'+esc(msg.file_url)+'"></audio></div>';
            else if(msg.type==='file'&&msg.file_url)bc=senderInfo+'<div class="message-file"><a href="'+esc(msg.file_url)+'" target="_blank" class="file-link">📎 '+esc(msg.file_name||msg.text)+'<span class="file-size">'+fmtSize(msg.file_size)+'</span></a></div>';
            else bc=senderInfo+'<div class="message-bubble">'+esc(msg.text)+'</div>';
            w.innerHTML=bc+'<span class="message-time">'+esc(msg.time)+(isSent?' ✓✓':'')+'</span>';
            messagesArea.appendChild(w);
        });
        const tb=document.createElement('div');tb.className='typing-bubble';tb.id='typingBubble';tb.style.display='none';tb.innerHTML='<span></span><span></span><span></span>';
        messagesArea.appendChild(tb);scrollBottom();
    }
    function scrollBottom(){requestAnimationFrame(()=>{messagesArea.scrollTop=messagesArea.scrollHeight;});}

    // ====== SEND (FIX: force append + scroll) ======
    async function sendMessage(){
        const text=messageInput.value.trim();
        if(!text)return;
        if(!activeContactId){alert('请先选择一个对话');return;}
        messageInput.value='';
        messageInput.style.height='auto';
        // Show immediately in DOM
        try{
            const now=new Date();
            const timeStr=now.toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'});
            const w=document.createElement('div');
            w.className='message-wrapper sent';
            w.innerHTML='<div class="message-bubble">'+esc(text)+'</div><span class="message-time">'+timeStr+' ✓✓</span>';
            // Remove empty state if present
            const es=messagesArea.querySelector('.empty-state');
            if(es)es.remove();
            // Insert before typing bubble
            const tb=document.getElementById('typingBubble');
            if(tb&&tb.parentNode===messagesArea){messagesArea.insertBefore(w,tb);}
            else{messagesArea.appendChild(w);}
            // Force scroll
            messagesArea.scrollTop=messagesArea.scrollHeight+200;
        }catch(e){console.error('DOM append error:',e);}
        updatePreview(activeContactId,text,'刚刚');
        // Send to server (non-blocking)
        apiPost('send',{conversation_id:activeContactId,content:text}).then(data=>{
            if(!data||data.error){console.error('Send failed:',data);}
        }).catch(e=>{console.error('Send error:',e);});
    }
    function updatePreview(id,msg,time){
        const card=document.querySelector('.contact-card[data-cid="'+id+'"]');
        if(card){const p=card.querySelector('.contact-preview');if(p)p.innerHTML='<span>'+esc(msg.length>35?msg.substring(0,35)+'...':msg)+'</span>';const t=card.querySelector('.contact-time');if(t)t.textContent=time;}
    }

    // ====== FILE UPLOAD ======
    const fileInput=document.createElement('input');fileInput.type='file';fileInput.style.display='none';
    fileInput.accept='image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.zip,.rar,.txt';document.body.appendChild(fileInput);
    document.querySelector('.attach-btn').addEventListener('click',()=>{if(!activeContactId){alert('请先选择对话');return;}fileInput.click();});
    fileInput.addEventListener('change',async()=>{
        const file=fileInput.files[0];if(!file)return;fileInput.value='';
        // 文件大小不限制
        const fd=new FormData();fd.append('file',file);fd.append('conversation_id',activeContactId);
        const up=document.createElement('div');up.className='message-wrapper sent';up.innerHTML='<div class="message-bubble" style="opacity:0.6">⏳ 上传中...</div>';
        messagesArea.appendChild(up);scrollBottom();
        const d=await apiUpload('upload_file',fd);up.remove();
        if(!d||d.error){alert(d?d.error:'上传失败');return;}
        appendServerMsg(d.message);updatePreview(activeContactId,'📎 '+file.name,'刚刚');
    });
    function appendServerMsg(msg){
        const es=messagesArea.querySelector('.empty-state');if(es)es.remove();
        const w=document.createElement('div');w.className='message-wrapper sent';w.style.animation='messageIn 0.3s ease';
        let bc='';
        if(msg.type==='image'&&msg.file_url)bc='<div class="message-image"><img src="'+esc(msg.file_url)+'" loading="lazy"></div>';
        else if(msg.type==='file'&&msg.file_url)bc='<div class="message-file"><a href="'+esc(msg.file_url)+'" target="_blank" class="file-link">📎 '+esc(msg.file_name||msg.text)+'<span class="file-size">'+fmtSize(msg.file_size)+'</span></a></div>';
        else bc='<div class="message-bubble">'+esc(msg.text)+'</div>';
        w.innerHTML=bc+'<span class="message-time">'+esc(msg.time)+' ✓✓</span>';
        messagesArea.appendChild(w);scrollBottom();
    }

    // ====== EMOJI ======
    const EMOJIS=['😀','😃','😄','😁','😆','😅','🤣','😂','🙂','🙃','😉','😊','😇','🥰','😍','🤩','😘','😗','😚','😙','😋','😛','😜','🤪','😝','🤑','🤗','🤭','🤫','🤔','🤐','🤨','😐','😑','😶','😏','😒','🙄','😬','🤥','😌','😔','😪','🤤','😴','😷','🤒','🤕','🤢','🤮','🤧','🥵','🥶','😱','😨','😰','😥','😢','😭','😤','😠','😡','🤬','💀','👻','👽','🤖','💩','😺','😸','❤️','🧡','💛','💚','💙','💜','🖤','🤍','💔','❣️','💕','💞','💓','💗','💖','💘','💝','💟','🔥','⭐','🌟','✨','⚡','💥','💫','🎉','🎊','🎈','👍','👎','👌','✌️','🤞','🤟','🤘','🤙','👋','🤚','👏','🙌','🤝','🙏','💪','🚀','🎯','💡','🔔','📌','📎','✏️','📝','☕','🍕','🍔','🍟','🌮','🍜','🍣','🍰','🍩','🍪','🎵','🎶','🎸','🎹','🥁','🎤','🎧','🎮','🎲','🏆'];
    function toggleEmoji(){
        if(emojiPicker){emojiPicker.remove();emojiPicker=null;return;}
        emojiPicker=document.createElement('div');emojiPicker.className='emoji-picker';
        emojiPicker.innerHTML='<div class="emoji-grid">'+EMOJIS.map(e=>'<span class="emoji-item" data-e="'+e+'">'+e+'</span>').join('')+'</div>';
        document.querySelector('.input-area').appendChild(emojiPicker);
        emojiPicker.querySelectorAll('.emoji-item').forEach(i=>{i.addEventListener('click',()=>{messageInput.value+=i.getAttribute('data-e');messageInput.focus();});});
        setTimeout(()=>{document.addEventListener('click',function cl(ev){if(!emojiPicker.contains(ev.target)&&!ev.target.closest('.emoji-btn')){if(emojiPicker){emojiPicker.remove();emojiPicker=null;}document.removeEventListener('click',cl);}});},10);
    }
    document.querySelector('.emoji-btn').addEventListener('click',e=>{e.stopPropagation();toggleEmoji();});

    // ====== NEW CHAT / GROUP ======
    let isGroupMode=false,selectedMembers=[];
    function updateModalUI(){
        const h=newChatModal.querySelector('.modal-header h3');h.textContent=isGroupMode?'创建群聊':'新对话';
        let ft=newChatModal.querySelector('.modal-footer');
        if(!ft){ft=document.createElement('div');ft.className='modal-footer';newChatModal.querySelector('.modal-box').appendChild(ft);}
        if(!isGroupMode){
            ft.innerHTML='<button class="btn-secondary" id="groupModeBtn">👥 创建群聊</button>';
            document.getElementById('groupModeBtn').addEventListener('click',()=>{isGroupMode=true;selectedMembers=[];updateModalUI();});
        }else{
            const sel=selectedMembers.map(m=>'<span class="selected-chip">'+esc(m.nickname)+'<span class="chip-x" data-id="'+m.id+'">×</span></span>').join('');
            ft.innerHTML='<div class="selected-chips">'+sel+'</div><div class="modal-btn-row"><button class="btn-secondary" id="backNorm">← 返回</button><button class="btn-primary" id="createGrp">创建群聊</button></div>';
            document.getElementById('backNorm').addEventListener('click',()=>{isGroupMode=false;selectedMembers=[];updateModalUI();});
            document.getElementById('createGrp').addEventListener('click',showGroupNameModal);
            ft.querySelectorAll('.chip-x').forEach(b=>{b.addEventListener('click',()=>{selectedMembers=selectedMembers.filter(m=>m.id!==+b.getAttribute('data-id'));updateModalUI();});});
        }
    }
    function showGroupNameModal(){
        if(selectedMembers.length<1){alert('至少选一个成员');return;}
        const m=createModal('输入群名称','<div class="form-group"><input type="text" id="groupNameInput" placeholder="给群聊取个名字..." autocomplete="off" maxlength="30"></div><div class="modal-btn-row"><button class="btn-secondary" id="cancelGroup">取消</button><button class="btn-primary" id="confirmGroup">创建 ('+selectedMembers.length+'人)</button></div>');
        const inp=m.querySelector('#groupNameInput');inp.focus();
        m.querySelector('#cancelGroup').addEventListener('click',()=>m.remove());
        m.querySelector('#confirmGroup').addEventListener('click',async()=>{
            const name=inp.value.trim();if(!name){inp.focus();return;}
            const d=await apiPost('create_group',{name,member_ids:selectedMembers.map(x=>x.id)});
            if(d?.success){m.remove();newChatModal.style.display='none';switchTab('chats');await loadContacts();switchContact(d.conversation_id);}
            else alert(d?d.error:'创建失败');
        });
        inp.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();m.querySelector('#confirmGroup').click();}});
    }
    modalClose.addEventListener('click',()=>{newChatModal.style.display='none';});
    newChatModal.addEventListener('click',e=>{if(e.target===newChatModal)newChatModal.style.display='none';});

    let st=null;
    userSearchInput.addEventListener('input',()=>{
        clearTimeout(st);const q=userSearchInput.value.trim();if(q.length<1){userSearchResults.innerHTML='';return;}
        st=setTimeout(async()=>{
            const d=await api('search_users',{q});if(!d?.users)return;
            const fd=await api('friends');const friendIds=fd?.friends?.map(f=>f.id)||[];
            if(!d.users.length){userSearchResults.innerHTML='<div class="search-empty">未找到用户</div>';return;}
            userSearchResults.innerHTML=d.users.map(u=>{
                const isFriend=friendIds.includes(u.id);const isSelected=selectedMembers.some(m=>m.id===u.id);
                const av=avHtml(u.avatar,u.avatar_color,u.avatar_url,'avatar avatar-sm');
                return '<div class="search-item'+(isSelected?' selected':'')+'" data-uid="'+u.id+'" data-friend="'+isFriend+'">'
                    +'<div class="search-item-avatar" data-uid="'+u.id+'">'+av+'</div>'
                    +'<div class="search-item-info"><span class="search-item-name">'+esc(u.nickname)+'</span><span class="search-item-user">@'+esc(u.username)+'</span></div>'
                    +'<div class="search-item-actions">'+(isFriend?'<span class="tag-friend">✓ 好友</span>':'<button class="btn-add-friend" data-uid="'+u.id+'">+ 好友</button>')+'</div></div>';
            }).join('');
            userSearchResults.querySelectorAll('.search-item-avatar').forEach(a=>{a.addEventListener('click',e=>{e.stopPropagation();showUserProfile(+a.getAttribute('data-uid'));});});
            userSearchResults.querySelectorAll('.btn-add-friend').forEach(b=>{b.addEventListener('click',async e=>{e.stopPropagation();
                const r=await apiPost('send_friend_request',{user_id:+b.getAttribute('data-uid')});if(r)b.outerHTML='<span class="tag-pending">已发送</span>';});});
            userSearchResults.querySelectorAll('.search-item').forEach(item=>{item.addEventListener('click',async()=>{
                const uid=+item.getAttribute('data-uid');const isFriend=item.getAttribute('data-friend')==='true';
                if(isGroupMode){if(!isFriend){alert('只能添加好友到群聊');return;}const idx=selectedMembers.findIndex(m=>m.id===uid);if(idx>=0)selectedMembers.splice(idx,1);else{const u=d.users.find(u=>u.id===uid);if(u)selectedMembers.push(u);}updateModalUI();userSearchInput.dispatchEvent(new Event('input'));}
                else{if(!isFriend){showUserProfile(uid);return;}const r=await apiPost('create_conversation',{user_id:uid});if(r?.conversation_id){newChatModal.style.display='none';switchTab('chats');await loadContacts();switchContact(r.conversation_id);}else if(r?.error==='need_friend'){showUserProfile(uid);}else alert(r?.error||'创建失败');}});});
        },300);
    });

    // ====== USER PROFILE ======
    async function showUserProfile(userId){
        const data=await api('user_profile',{user_id:userId});if(!data?.user)return;
        const u=data.user,fs=data.friend_status,isMe=u.id===window.__CHAT_DATA__.currentUserId;
        let actions='';
        if(isMe)actions='<div class="profile-me">这是你自己</div>';
        else if(fs==='accepted')actions='<div class="profile-actions"><button class="btn-primary" id="startChat">💬 发消息</button><button class="btn-danger-outline" id="rmFriend">删除好友</button></div>';
        else if(fs==='pending')actions='<div class="profile-pending">⏳ 好友请求待处理</div>';
        else actions='<button class="btn-primary" id="addFriendBtn" style="width:100%">➕ 添加好友</button>';
        const av=avHtml(u.avatar,u.avatar_color,u.avatar_url,'avatar avatar-xl');
        const m=createModal('个人资料','<div class="profile-view"><div class="profile-avatar-wrap">'+av+'</div><h3 class="profile-name">'+esc(u.nickname)+'</h3><p class="profile-username">@'+esc(u.username)+'</p>'+(u.bio?'<p class="profile-bio">'+esc(u.bio)+'</p>':'')+'<p class="profile-status">'+(u.online?'<span class="status-online">● 在线</span>':'<span class="status-offline">○ 离线</span>')+'</p>'+actions+'</div>');
        const addBtn=m.querySelector('#addFriendBtn');
        if(addBtn)addBtn.addEventListener('click',async()=>{const r=await apiPost('send_friend_request',{user_id:u.id});if(r){alert(r.message||r.error);m.remove();}});
        const chatBtn=m.querySelector('#startChat');
        if(chatBtn)chatBtn.addEventListener('click',async()=>{m.remove();const r=await apiPost('create_conversation',{user_id:u.id});if(r?.conversation_id){switchTab('chats');await loadContacts();switchContact(r.conversation_id);}else alert(r?.error||'创建对话失败');});
        const rmBtn=m.querySelector('#rmFriend');
        if(rmBtn)rmBtn.addEventListener('click',async()=>{if(!await customConfirm('确定删除该好友？'))return;await apiPost('remove_friend',{user_id:u.id});m.remove();});
    }

    // ====== GROUP MEMBERS ======
    async function showGroupMembers(convId){
        const d=await api('group_members',{conversation_id:convId});if(!d?.members)return;
        const items=d.members.map(m=>{const av=avHtml(m.avatar,m.avatar_color,m.avatar_url,'avatar avatar-sm');
            return '<div class="member-row" data-uid="'+m.id+'">'+av+'<span class="member-name">'+esc(m.nickname)+'</span>'+(m.id===window.__CHAT_DATA__.currentUserId?'<span class="tag-me">我</span>':'')+(m.online?'<span class="online-dot" style="width:8px;height:8px;"></span>':'')+'</div>';}).join('');
        const m=createModal('群成员 ('+d.members.length+'人)','<div class="members-list">'+items+'</div>');
        m.querySelectorAll('.member-row').forEach(row=>{row.addEventListener('click',()=>{const uid=+row.getAttribute('data-uid');if(uid!==window.__CHAT_DATA__.currentUserId)showUserProfile(uid);});});
    }
    async function showAddMember(convId){
        const d=await api('group_members',{conversation_id:convId});const existing=d?.members?.map(m=>m.id)||[];
        const m=createModal('添加成员','<input type="text" id="addMemSearch" placeholder="搜索用户..." autocomplete="off"><div id="addMemResults" class="search-results"></div>');
        const inp=m.querySelector('#addMemSearch');inp.focus();let sst=null;
        inp.addEventListener('input',()=>{clearTimeout(sst);const q=inp.value.trim();if(q.length<1){m.querySelector('#addMemResults').innerHTML='';return;}
            sst=setTimeout(async()=>{const sd=await api('search_users',{q});if(!sd?.users)return;
                m.querySelector('#addMemResults').innerHTML=sd.users.filter(u=>!existing.includes(u.id)).map(u=>{const av=avHtml(u.avatar,u.avatar_color,u.avatar_url,'avatar avatar-sm');
                    return '<div class="search-item" data-uid="'+u.id+'">'+av+'<div class="search-item-info"><span class="search-item-name">'+esc(u.nickname)+'</span></div><span class="btn-add-friend">添加</span></div>';}).join('');
                m.querySelectorAll('.search-item').forEach(item=>{item.addEventListener('click',async()=>{const uid=+item.getAttribute('data-uid');const r=await apiPost('add_member',{conversation_id:convId,user_id:uid});if(r?.success){item.remove();existing.push(uid);}else alert(r?.error||'添加失败');});});
            },300);});
    }

    // ====== SETTINGS ======
    document.querySelector('.user-info').style.cursor='pointer';
    document.querySelector('.user-info').addEventListener('click',showSettings);
    async function showSettings(){
        const d=await api('me');if(!d?.user)return;const u=d.user;
        const av=avHtml(u.avatar_letter,u.avatar_color,null,'avatar avatar-xl');
        const m=createModal('个人设置','<div class="settings-section"><div class="settings-avatar"><div class="avatar-clickable">'+av+'</div></div><div class="form-group"><label>昵称</label><input type="text" id="sNick" value="'+esc(u.nickname)+'"></div><div class="form-group"><label>个性签名</label><input type="text" id="sBio" value="'+esc(u.bio||'')+'" placeholder="写点什么..."></div><div class="form-group"><label>用户名</label><input type="text" value="'+esc(u.username)+'" disabled></div><button class="btn-primary" id="saveProfile" style="width:100%">保存资料</button></div><div class="settings-divider"></div><div class="settings-section"><h4 class="settings-title">修改密码</h4><div class="form-group"><label>旧密码</label><input type="password" id="sOldPwd"></div><div class="form-group"><label>新密码</label><input type="password" id="sNewPwd" placeholder="至少6个字符"></div><button class="btn-secondary" id="changePwd" style="width:100%">修改密码</button></div><div class="settings-divider"></div><div class="settings-section"><button class="btn-danger-outline" id="deleteAccount" style="width:100%">🗑️ 删除账号</button></div>',{wide:true});
        // avatar upload removed
        m.querySelector('#saveProfile').addEventListener('click',async()=>{const nick=m.querySelector('#sNick').value.trim(),bio=m.querySelector('#sBio').value.trim();if(!nick){alert('昵称不能为空');return;}const r=await apiPost('update_profile',{nickname:nick,bio});if(r?.success){document.querySelector('.user-name').textContent=nick;alert('保存成功');}else alert(r?.error||'保存失败');});
        m.querySelector('#changePwd').addEventListener('click',async()=>{const o=m.querySelector('#sOldPwd').value,n=m.querySelector('#sNewPwd').value;if(!o||!n){alert('请填写密码');return;}if(n.length<6){alert('新密码至少6字符');return;}const r=await apiPost('change_password',{old_password:o,new_password:n});if(r?.success){alert('密码已修改');m.querySelector('#sOldPwd').value='';m.querySelector('#sNewPwd').value='';}else alert(r?.error||'修改失败');});
        // Delete account
        m.querySelector('#deleteAccount').addEventListener('click',()=>{
            m.remove();
            const dm=createModal('删除账号','<p style="text-align:center;color:var(--danger);margin-bottom:12px;font-weight:600">⚠️ 此操作不可撤销</p><p style="text-align:center;color:var(--text-secondary);margin-bottom:16px">所有聊天记录、好友关系都将被永久删除</p><div class="form-group"><label>输入密码确认</label><input type="password" id="delPassword" placeholder="输入你的密码"></div><div class="modal-btn-row"><button class="btn-secondary" id="cancelDel">取消</button><button class="btn-danger-outline" id="confirmDel">确认删除</button></div>',{wide:true});
            dm.querySelector('#cancelDel').addEventListener('click',()=>dm.remove());
            dm.querySelector('#confirmDel').addEventListener('click',async()=>{
                const pwd=dm.querySelector('#delPassword').value;
                if(!pwd){alert('请输入密码');return;}
                const r=await apiPost('delete_account',{password:pwd});
                if(r?.success){dm.remove();location.href='login.php';}
                else alert(r?.error||'删除失败');
            });
        });
    }

    logoutBtn.addEventListener('click',()=>{
        const m=createModal('退出登录','<p style="text-align:center;color:var(--text-secondary);margin-bottom:20px">确定要退出登录吗？</p><div class="modal-btn-row"><button class="btn-secondary" id="cancelLogout">取消</button><button class="btn-danger-outline" id="confirmLogout">退出</button></div>');
        m.querySelector('#cancelLogout').addEventListener('click',()=>m.remove());
        m.querySelector('#confirmLogout').addEventListener('click',async()=>{m.remove();await api('logout');location.href='login.php';});
    });
    let ct=null;searchInput.addEventListener('input',()=>{clearTimeout(ct);ct=setTimeout(loadContacts,300);});
    filterTabs.forEach(tab=>{tab.addEventListener('click',()=>{
        filterTabs.forEach(t=>t.classList.remove('active'));tab.classList.add('active');
        currentFilter=tab.getAttribute('data-filter');
        if(currentFilter==='friends'){loadFriendsList();}
        else{showChatsList();loadContacts();}
    });});
    sendBtn.addEventListener('click',sendMessage);
    messageInput.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();sendMessage();}});
    messageInput.addEventListener('input',()=>{messageInput.style.height='auto';messageInput.style.height=Math.min(messageInput.scrollHeight,120)+'px';});
    backBtn.addEventListener('click',()=>{sidebar.classList.remove('hidden-on-mobile');chatMain.classList.add('hidden-on-mobile');activeContactId=null;});

    function startPoll(){if(pollTimer)clearInterval(pollTimer);pollTimer=setInterval(async()=>{
            if(currentFilter!=='friends')await loadContacts();
            updateRequestBadge();
            // Refresh current chat messages
            if(activeContactId){
                const d=await api('messages',{conversation_id:activeContactId});
                if(d?.messages){
                    const domCount=messagesArea.querySelectorAll('.message-wrapper').length;
                    if(d.messages.length!==domCount)loadMessages(activeContactId);
                }
            }
        },POLL_INTERVAL);}

    // ====== CHAT SETTINGS BUTTON ======
    const chatSettingsBtn=document.getElementById('chatSettingsBtn');
    if(chatSettingsBtn){
        chatSettingsBtn.addEventListener('click',()=>{
            if(!activeContactData)return;
            if(activeContactData.is_group){
                showGroupSettings(activeContactData.id);
            }else{
                if(activeContactData.user_id)showUserProfile(activeContactData.user_id);
            }
        });
    }

    async function showGroupSettings(convId){
        const d=await api('group_members',{conversation_id:convId});if(!d?.members)return;
        const members=d.members;
        const av='<div class="avatar avatar-xl" style="background:'+esc(activeContactData.avatar_color)+';">'+esc(activeContactData.avatar)+'</div>';
        let body='<div class="profile-view">'
            +'<div class="settings-avatar"><div class="avatar-clickable">'+av+'</div></div>'
            +'<h3 class="profile-name">'+esc(activeContactData.name)+'</h3>'
            +'<p class="profile-username">'+members.length+' 名成员</p>'
            +'</div>'
            +'<div class="settings-divider"></div>'
            +'<div class="tab-section-title">成员列表</div>'
            +'<div class="members-list">'
            +members.map(m=>{
                const mav=m.avatar_url?'<img class="avatar avatar-sm" src="'+esc(m.avatar_url)+'" style="object-fit:cover">':'<div class="avatar avatar-sm" style="background:'+esc(m.avatar_color)+';">'+esc(m.avatar)+'</div>';
                return '<div class="member-row" data-uid="'+m.id+'">'+mav+'<span class="member-name">'+esc(m.nickname)+'</span>'+(m.id===window.__CHAT_DATA__.currentUserId?'<span class="tag-me">我</span>':'')+'</div>';
            }).join('')
            +'</div>'
            +'<button class="btn-secondary" id="grpAddMember" style="width:100%;margin-top:12px">➕ 添加成员</button>';
        const m=createModal('群聊设置',body,{wide:true});
        
        m.querySelector('#grpAddMember').addEventListener('click',()=>{m.remove();showAddMember(convId);});
        m.querySelectorAll('.member-row').forEach(row=>{row.addEventListener('click',()=>{const uid=+row.getAttribute('data-uid');if(uid!==window.__CHAT_DATA__.currentUserId)showUserProfile(uid);});});
    }

    async function init(){
        await loadContacts();updateRequestBadge();startPoll();
        if(!isMobile&&contacts.length)switchContact(contacts[0].id,contactsList.querySelector('.contact-card'));
    }
    init();
})();
