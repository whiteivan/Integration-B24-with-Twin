timeoutID = window.setTimeout(ext_ready, 5000);
// setInterval(async () => {
//     const company_id = side_panel_iframe_el.src.match(/\/crm\/company\/details\/(\d+)/)[1];
//     const phone_numbers = Array.from(phone_elements).map(el => el.title || el.href);
//     const clear_phone_numbers = phone_numbers.map(phone => phone.replace(/\D/g, ''));
//     let data, response, totalCount;
//     data = {
//         company_id: company_id,
//         phones: clear_phone_numbers
//     };
//     response = await send_curl('https://srv.inspectrum.space/b24_get_info_cfd46b41c00df6', data);
//     readyCount = response.phones.filter(phone => phone.status_phone === 'ready_to_call').length;
//     totalCount = clear_phone_numbers.length;
//     const phone_block_el = side_panel_iframe_el.contentDocument.querySelectorAll('div[data-cid="PHONE"]')[0];
//     updated_one_status(response)
// },30000);
async function ext_ready() {

    const user_block_el = document.getElementById("user-block");
    const user_id = user_block_el.getAttribute("data-user-id");
    const company = document.querySelector("head > title").textContent;
    const side_panel_iframe_el = document.getElementsByClassName('side-panel-iframe')[0];
    //console.log(side_panel_iframe_el);

    const responsible_block_el = side_panel_iframe_el.contentDocument.querySelectorAll('.crm-widget-employee-name')[0];
    //console.log(responsible_block_el);
    const responsible_user_id = responsible_block_el.getAttribute("href").match(/\/user\/(\d+)\//)[1];
    //console.log(responsible_user_id);

    const company_id = side_panel_iframe_el.src.match(/\/crm\/company\/details\/(\d+)/)[1];
    //console.log(company_id);

    const phone_block_el = side_panel_iframe_el.contentDocument.querySelectorAll('div[data-cid="PHONE"]')[0];
    //console.log(phone_block_el);

    const phone_elements = phone_block_el.querySelectorAll('.crm-entity-phone-number');
    const phone_numbers = Array.from(phone_elements).map(el => el.title || el.href);
    const clear_phone_numbers = phone_numbers.map(phone => phone.replace(/\D/g, ''));
    //console.log(clear_phone_numbers);

    const phone_block_header_el = phone_block_el.querySelectorAll('.ui-entity-editor-block-title-text')[0];
    //console.log(phone_block_header_el);

    function set_autocall_button_state(button, type, state) {
        switch (type) {
            case 'all_start_pause':
                switch (state) {
                    case 'ready':
                        button.innerHTML = '▶️';
                        button.setAttribute('data-autocall-all-state', 'ready');
                        button.setAttribute('title', 'Запустить очередь обзвона по всем доступным номерам');
                        break;
                    case 'pause':
                        button.innerHTML = '⏸️';
                        button.setAttribute('data-autocall-all-state', 'pause');
                        button.setAttribute('title', 'Приостановить обзвон по запущенным номерам');
                        break;
                    case 'resume':
                        button.innerHTML = '⏯️';
                        button.setAttribute('data-autocall-all-state', 'resume');
                        button.setAttribute('title', 'Возобновить обзвон по приостановленным номерам');
                        break;
                    case 'blocked':
                        button.innerHTML = '▶️';
                        button.setAttribute('data-autocall-all-state', 'blocked');
                        button.setAttribute('title', 'Все номера заблокированы на 7 дней');
                        button.style.opacity = '0.1';
                        break;
                }
                break;
            case 'all_stop_block':
                switch (state) {
                    case 'unavailable':
                        button.innerHTML = '⏹️';
                        button.setAttribute('data-autocall-all-state', 'unavailable');
                        button.setAttribute('title', 'Остановить обзвон по всем номерам'); // TODO text
                        button.style.opacity = '0.1';
                        break;
                    case 'stop':
                        button.innerHTML = '⏹️';
                        button.setAttribute('data-autocall-all-state', 'stop');
                        button.setAttribute('title', 'Остановить обзвон по всем номерам');
                        button.style.opacity = '1';
                        break;
                    case 'blocked':
                        button.innerHTML = '▶️';
                        button.setAttribute('data-autocall-all-state', 'blocked');
                        button.setAttribute('title', 'Все номера заблокированы на 7 дней');
                        button.style.opacity = '0.1';
                        break;
                }
                break;
            case 'one_start_stop':
                switch (state) {
                    case 'ready_to_call':
                        button.innerHTML = '▶️';
                        button.setAttribute('data-autocall-one-status', 'ready_to_call');
                        button.setAttribute('title', 'Запустить обзвон по номеру');
                        button.style.opacity = '1';
                        break;
                    case 'in_pause':
                        button.innerHTML = '⏯️';
                        button.setAttribute('data-autocall-one-status', 'in_pause');
                        button.setAttribute('title', 'Возобновить обзвон по номеру');
                        button.style.opacity = '1';
                        break;
                    case 'in_progress':
                        button.innerHTML = '⏹️';
                        button.setAttribute('data-autocall-one-status', 'in_progress');
                        button.setAttribute('title', 'Остановить обзвон по номеру');
                        button.style.opacity = '1';
                        break;
                    case 'in_queue':
                        button.innerHTML = '❌';
                        button.setAttribute('data-autocall-one-status', 'in_queue');
                        button.setAttribute('title', 'Убрать номер из очереди обзвона');
                        button.style.opacity = '1';
                        break;
                    case 'block':
                        button.innerHTML = '▶️';
                        button.setAttribute('data-autocall-one-status', 'block');
                        button.setAttribute('title', 'Номер заблокирован на 7 дней');
                        button.style.opacity = '0.1';
                        break;
                }
                break;
        }
    }

    let readyCount, totalCount, phones_from_db, data, response, status, phone_number, parent_block, phones, in_progress_count;
    data = {
        company_id: company_id,
        phones: clear_phone_numbers
    };
    response = await send_curl('https://link/b24_get_info', data);
    readyCount = response.phones.filter(phone => phone.status_phone === 'ready_to_call').length;
    totalCount = clear_phone_numbers.length;
    phones_from_db = response.phones;
    console.log(phones_from_db);


    const autocall_all_button_1_el = document.createElement('div');
    autocall_all_button_1_el.classList.add('autocall_all_action');
    autocall_all_button_1_el.style.position = 'absolute';
    autocall_all_button_1_el.style.left = '60px';
    autocall_all_button_1_el.style.marginRight = '5px';
    autocall_all_button_1_el.style.cursor = 'pointer';

    const autocall_all_button_2_el = document.createElement('div');
    autocall_all_button_2_el.classList.add('autocall_all_action');
    autocall_all_button_2_el.style.position = 'absolute';
    autocall_all_button_2_el.style.left = '80px';
    autocall_all_button_2_el.style.marginRight = '5px';
    autocall_all_button_2_el.style.cursor = 'pointer';

    const autocall_all_status_el = document.createElement('div');
    autocall_all_status_el.classList.add('autocall_all_status');
    autocall_all_status_el.style.position = 'absolute';
    autocall_all_status_el.style.left = '100px';
    autocall_all_status_el.style.fontSize = '10';
    autocall_all_status_el.style.color = '#999999';


    if (response.phones.filter(phone => phone.status_phone === 'in_progress').length > 0) {
        set_autocall_button_state(autocall_all_button_1_el, 'all_start_pause', 'pause');
        set_autocall_button_state(autocall_all_button_2_el, 'all_stop_block', 'stop');
        autocall_all_status_el.setAttribute('data-autocall-all-state', 'resume');
        let in_progress_count = response.phones.filter(phone => phone.status_phone === 'in_queue').length + 1;
        autocall_all_status_el.innerHTML = `Идет обзвон ${in_progress_count}/${totalCount}`;
    } else if (response.phones.filter(phone => phone.status_phone === 'in_pause').length > 0) {
        set_autocall_button_state(autocall_all_button_1_el, 'all_start_pause', 'resume');
        set_autocall_button_state(autocall_all_button_2_el, 'all_stop_block', 'stop');
        autocall_all_status_el.setAttribute('data-autocall-all-state', 'pause');
        let in_pause_count = response.phones.filter(phone => phone.status_phone === 'in_queue').length + 1;
        autocall_all_status_el.innerHTML = `Пауза обзвона ${in_pause_count}/${totalCount}`;
    } else if (readyCount > 0) {
        set_autocall_button_state(autocall_all_button_1_el, 'all_start_pause', 'ready');
        set_autocall_button_state(autocall_all_button_2_el, 'all_stop_block', 'unavailable');
        autocall_all_status_el.setAttribute('data-autocall-all-state', 'ready');
        autocall_all_status_el.innerHTML = `Готовы ${readyCount}/${totalCount} к обзвону`;
    } else {
        set_autocall_button_state(autocall_all_button_1_el, 'all_start_pause', 'blocked');
        set_autocall_button_state(autocall_all_button_2_el, 'all_stop_block', 'unavailable');
        autocall_all_status_el.innerHTML = `Готовы ${readyCount}/${totalCount} к обзвону`;
    }


    phone_block_header_el.parentNode.appendChild(autocall_all_button_1_el);
    phone_block_header_el.parentNode.appendChild(autocall_all_button_2_el);
    phone_block_header_el.parentNode.appendChild(autocall_all_status_el);

    autocall_all_button_1_el.addEventListener('click', async function (event) {
        event.preventDefault();
        event.stopPropagation();
        let autocall_all_status = this.getAttribute('data-autocall-all-state');
        switch (autocall_all_status) {
            case 'ready':
                phones = phones_from_db.filter(phone => phone.status_phone === 'ready_to_call').map(phone => ({ phone: phone.phone, action_id: 1 }));
                data = {
                    user_id: user_id,
                    responsible_id: responsible_user_id,
                    company: company,
                    company_id: company_id,
                    phones: phones
                };
                response = await send_curl("https://link/b24", data);
                phones_from_db = phones_from_db.map(item => {
                    const updated_phone = response.phones.find(p => p.phone === item.phone);
                    return updated_phone ? { ...item, status_phone: updated_phone.status } : item;
                });

                console.log("194");
                console.log(phones_from_db);
                console.log(response);

                set_autocall_button_state(autocall_all_button_1_el, 'all_start_pause', 'pause');
                set_autocall_button_state(autocall_all_button_2_el, 'all_stop_block', 'stop');

                autocall_all_status_el.setAttribute('data-autocall-all-state', 'calling');
                in_progress_count = response.phones.filter(phone => phone.status === 'in_progress' || phone.status === 'in_queue').length;
                autocall_all_status_el.innerHTML = `Идёт обзвон ${in_progress_count}/${totalCount}`;

                // await updated_one_status(phones_from_db, phone_block_el, autocall_one_button_1_el);
                // console.log(phones_from_db);
                for (let element of phone_block_el.querySelectorAll('.autocall_one_action')) {
                    let autocall_one_status = element.getAttribute('data-autocall-one-status');
                    let autocall_phone_id = element.getAttribute('data-phone-id');
                    parent_block = element.closest('.crm-entity-widget-content-block-mutlifield');
                    phone_number = parent_block.querySelector('.crm-entity-phone-number').getAttribute('title').replace(/\D/g, '');

                    switch (autocall_one_status) {
                        case 'ready_to_call':
                            // const autocallOneActions = Array.from(phone_block_el.querySelectorAll('.autocall_one_action'));
                            // let autocall_one_button_1_active_el = autocallOneActions.find(
                            //     element => element.getAttribute('data-autocall-one-status') === 'in_progress'
                            // ); autocall_one_button_1_active_el && autocall_phone_id !== autocall_one_button_1_active_el.getAttribute("data-phone-id")

                            //  console.log(autocall_one_button_1_active_el);
                            if (phones_from_db.find(phone => phone.phone === phone_number).status_phone !== 'in_progress') {
                                set_autocall_button_state(element, 'one_start_stop', phones_from_db.find(phone => phone.phone === phone_number).status_phone);
                                element.nextSibling.setAttribute('data-autocall-one-status', phones_from_db.find(phone => phone.phone === phone_number).status_phone);
                                //element.nextSibling.setAttribute('autocall_one_action', phones_from_db.find(phone => phone.phone === phone_number).status_phone);
                                console.log(phone_number);
                                element.nextSibling.innerHTML = 'В очереди обзвона';
                            } else {
                                set_autocall_button_state(element, 'one_start_stop', phones_from_db.find(phone => phone.phone === phone_number).status_phone);
                                element.nextSibling.setAttribute('data-autocall-one-status', phones_from_db.find(phone => phone.phone === phone_number).status_phone);
                                //element.nextSibling.setAttribute('autocall_one_action', phones_from_db.find(phone => phone.phone === phone_number).status_phone);
                                console.log(phone_number);
                                element.nextSibling.innerHTML = 'Идёт обзвон';
                            }
                            break;
                    }
                };
                break;
            case 'pause':
                phones = phones_from_db.filter(item => item.status_phone === 'in_progress').map(phone => ({ phone: phone.phone, action_id: 3 }));
                data = {
                    user_id: user_id,
                    responsible_id: responsible_user_id,
                    company: company,
                    company_id: company_id,
                    phones: phones
                };
                response = await send_curl("https://link/b24", data);
                phones_from_db = phones_from_db.map(item => {
                    const updated_phone = response.phones.find(p => p.phone === item.phone);
                    return updated_phone ? { ...item, status_phone: updated_phone.status } : item;
                });

                set_autocall_button_state(autocall_all_button_1_el, 'all_start_pause', 'resume');
                // set_autocall_button_state(autocall_all_button_2_el, 'all_stop_block', 0);
                autocall_all_status_el.setAttribute('data-autocall-all-state', 'pause');
                let in_pause_count = response.phones.filter(phone => phone.status === 'in_pause' || phone.status === 'in_queue').length;
                autocall_all_status_el.innerHTML = `Пауза обзвона ${in_pause_count}/${totalCount}`;

                phone_block_el.querySelectorAll('.autocall_one_action').forEach(function (element, index) {
                    let autocall_one_status = element.getAttribute('data-autocall-one-status');
                    let autocall_phone_id = element.getAttribute('data-phone-id');

                    switch (autocall_one_status) {
                        case 'in_progress':
                            set_autocall_button_state(element, 'one_start_stop', 'in_pause');

                            element.nextSibling.setAttribute('data-autocall-one-status', 'in_pause');
                            element.nextSibling.setAttribute('autocall_one_action', 'in_pause');
                            element.nextSibling.innerHTML = 'На паузе';
                            break;
                    }
                });
                break;
            case 'resume':
                phones = phones_from_db.filter(item => item.status_phone === 'in_pause').map(phone => ({ phone: phone.phone, action_id: 1 }));
                data = {
                    user_id: user_id,
                    responsible_id: responsible_user_id,
                    company: company,
                    company_id: company_id,
                    phones: phones
                };
                response = await send_curl("https://link/b24", data);
                phones_from_db = phones_from_db.map(item => {
                    const updated_phone = response.phones.find(p => p.phone === item.phone);
                    return updated_phone ? { ...item, status_phone: updated_phone.status } : item;
                });
                set_autocall_button_state(autocall_all_button_1_el, 'all_start_pause', 'pause');
                // set_autocall_button_state(autocall_all_button_2_el, 'all_stop_block', 0);
                autocall_all_status_el.setAttribute('data-autocall-all-state', 'calling');
                in_progress_count = response.phones.filter(phone => phone.status === 'in_progress' || phone.status === 'in_queue').length;
                autocall_all_status_el.innerHTML = `Обзвон возобновлён ${in_progress_count}/${totalCount}`;

                phone_block_el.querySelectorAll('.autocall_one_action').forEach(function (element, index) {
                    let autocall_one_status = element.getAttribute('data-autocall-one-status');
                    let autocall_phone_id = element.getAttribute('data-phone-id');

                    switch (autocall_one_status) {
                        case 'in_pause':
                            set_autocall_button_state(element, 'one_start_stop', 'in_progress');

                            element.nextSibling.setAttribute('data-autocall-one-status', 'in_progress');
                            element.nextSibling.setAttribute('autocall_one_action', 'in_progress');
                            element.nextSibling.innerHTML = 'Обзвон возобновлён';
                            break;
                    }
                });
                break;
        }
    });

    autocall_all_button_2_el.addEventListener('click', async function (event) {
        event.preventDefault();
        event.stopPropagation();
        let autocall_all_status = this.getAttribute('data-autocall-all-state');
        switch (autocall_all_status) {
            case 'stop':
                console.log(phones_from_db);
                let request_data = [];
                //phone_block_el.querySelectorAll('.autocall_one_action').forEach(async function (element, index) {
                for (let element of phone_block_el.querySelectorAll('.autocall_one_action')) {
                    //updated_one_status(phones_from_db,phone_block_el,element);
                    //console.log(phones_from_db);
                    let autocall_one_status = element.getAttribute('data-autocall-one-status');
                    //console.log(autocall_one_status);
                    let autocall_phone_id = element.getAttribute('data-phone-id');
                    parent_block = element.closest('.crm-entity-widget-content-block-mutlifield');
                    phone_number = parent_block.querySelector('.crm-entity-phone-number').getAttribute('title').replace(/\D/g, '');
                    //console.log(phone_number);
                    switch (autocall_one_status) {
                        case 'in_progress':
                            request_data.push({
                                phone: phone_number,
                                action_id: 2
                            });
                            element.nextSibling.innerHTML = 'Номер заблокирован на 7 дней';
                            break;
                        case 'in_pause':
                            request_data.push({
                                phone: phone_number,
                                action_id: 2
                            });
                            element.nextSibling.innerHTML = 'Номер заблокирован на 7 дней';
                            break;
                        case 'in_queue':
                            request_data.push({
                                phone: phone_number,
                                action_id: 2
                            });
                            element.nextSibling.innerHTML = 'Готов к обзвону';
                            break;
                    }
                    
                };
                data = {
                    user_id: user_id,
                    responsible_id: responsible_user_id,
                    company: company,
                    company_id: company_id,
                    phones: request_data
                };
                response = await send_curl("https://link/b24", data);
                console.log(response);
                console.log(phones_from_db);
                phones_from_db = phones_from_db.map(item => {
                    const updated_phone = response.phones.find(p => p.phone === item.phone);
                    return updated_phone ? { ...item, status_phone: updated_phone.status } : item;
                });
                console.log(phones_from_db);

                updated_one_status(phones_from_db,phone_block_el);

                if ((readyCount = phones_from_db.filter(phone => phone.status_phone === 'ready_to_call').length) > 0) {
                    set_autocall_button_state(autocall_all_button_1_el, 'all_start_pause', 'ready');
                    set_autocall_button_state(autocall_all_button_2_el, 'all_stop_block', 'unavailable');
                    autocall_all_status_el.setAttribute('data-autocall-all-state', 'ready');
                    autocall_all_status_el.innerHTML = `Готовы ${readyCount}/${totalCount} к обзвону`;
                } else {
                    set_autocall_button_state(autocall_all_button_1_el, 'all_start_pause', 'blocked');
                    set_autocall_button_state(autocall_all_button_2_el, 'all_stop_block', 'unavailable');
                    autocall_all_status_el.setAttribute('data-autocall-all-state', 'blocked');
                    autocall_all_status_el.innerHTML = `Все номера заблокированы на 7 дней`;
                }
                break;
        }
    });

    let autocall_one_button_1_el;
    phone_block_el.querySelectorAll('.crm-entity-widget-content-block-mutlifield-value').forEach(function (element, index) {
        autocall_one_button_1_el = document.createElement('div');
        autocall_one_button_1_el.classList.add('autocall_one_action');
        autocall_one_button_1_el.style.position = 'absolute';
        autocall_one_button_1_el.style.left = '150px';
        autocall_one_button_1_el.style.marginRight = '5px';
        autocall_one_button_1_el.style.zIndex = '100';
        autocall_one_button_1_el.style.cursor = 'pointer';
        autocall_one_button_1_el.setAttribute('data-phone-id', index);
        phone_number = element.querySelector('.crm-entity-phone-number').getAttribute('title').replace(/\D/g, '');
        //console.log(phone_number);
        const phone_data = phones_from_db.find(phone => phone.phone === phone_number);
        set_autocall_button_state(autocall_one_button_1_el, 'one_start_stop', phone_data.status_phone);

        const autocall_one_status_el = document.createElement('div');
        autocall_one_status_el.classList.add('autocall_one_status');
        autocall_one_status_el.style.position = 'absolute';
        autocall_one_status_el.style.left = '180px';
        autocall_one_status_el.style.zIndex = '100';
        autocall_one_status_el.style.fontSize = '10';
        autocall_one_status_el.style.color = '#999999';

        switch (phone_data.status_phone) {
            case 'ready_to_call':
                autocall_one_status_el.innerHTML = 'Готов к обзвону';
                break;
            case 'in_queue':
                autocall_one_status_el.innerHTML = 'В очереди обзвона';
                break;
            case 'in_progress':
                autocall_one_status_el.innerHTML = 'Идёт обзвон';
                break;
            case 'block':
                autocall_one_status_el.innerHTML = 'Номер заблокирован на 7 дней';
                break;
            case 'in_pause':
                autocall_one_status_el.innerHTML = 'На паузе';
                break;
        }

        autocall_one_status_el.setAttribute('data-phone-id', index);
        autocall_one_status_el.setAttribute('data-autocall-one-status', phone_data.status_phone);
        autocall_one_button_1_el.setAttribute('data-autocall-one-status', phone_data.status_phone);

        element.parentNode.appendChild(autocall_one_button_1_el);
        element.parentNode.appendChild(autocall_one_status_el);


        autocall_one_button_1_el.addEventListener('mousedown', function (event) {
            event.preventDefault();
            event.stopPropagation();
        });
        autocall_one_button_1_el.addEventListener('mouseup', function (event) {
            event.preventDefault();
            event.stopPropagation();
        });

        autocall_one_button_1_el.addEventListener('click', async function (event) {
            event.preventDefault();
            event.stopPropagation();
            phone_number = element.querySelector('.crm-entity-phone-number').getAttribute('title').replace(/\D/g, '');
            let autocall_one_status = this.getAttribute('data-autocall-one-status');
            let autocall_phone_id = this.getAttribute('data-phone-id');

            switch (autocall_one_status) {
                case 'ready_to_call':
                    data = {
                        user_id: user_id,
                        responsible_id: responsible_user_id,
                        company: company,
                        company_id: company_id,
                        phones: [
                            {
                                phone: phone_number,
                                action_id: 1
                            }
                        ]
                    };
                    response = await send_curl("https://link/b24", data);
                    status = response.phones[0].status;
                    phones_from_db.find(item => item.phone === phone_number).status_phone = status;
                    set_autocall_button_state(autocall_one_button_1_el, 'one_start_stop', status);
                    autocall_one_status_el.setAttribute('data-autocall-one-status', status);
                    //autocall_one_button_1_el.setAttribute('data-autocall-one-status', status);
                    autocall_one_status_el.innerHTML = 'Идёт обзвон';
                    in_progress_count = phones_from_db.filter(phone => phone.status_phone === 'in_progress' || phone.status_phone === 'in_queue').length;
                    set_autocall_button_state(autocall_all_button_1_el, 'all_start_pause', 'pause');
                    set_autocall_button_state(autocall_all_button_2_el, 'all_stop_block', 'stop');
                    autocall_all_status_el.setAttribute('data-autocall-all-state', 'calling');
                    autocall_all_status_el.innerHTML = `Идет обзвон ${in_progress_count}/${totalCount}`;
                    console.log(phones_from_db);
                    break;
                case 'in_progress':
                    data = {
                        user_id: user_id,
                        responsible_id: responsible_user_id,
                        company: company,
                        company_id: company_id,
                        phones: [
                            {
                                phone: phone_number,
                                action_id: 2
                            }
                        ]
                    };
                    response = await send_curl("https://link/b24", data);
                    status = response.phones[0].status;
                    console.log(status);
                    phones_from_db.find(item => item.phone === phone_number).status_phone = status;
                    set_autocall_button_state(autocall_one_button_1_el, 'one_start_stop', status);
                    autocall_one_status_el.setAttribute('data-autocall-one-status', status);
                    // autocall_one_button_1_el.setAttribute('data-autocall-one-status', status);
                    autocall_one_status_el.innerHTML = 'Номер заблокирован на 7 дней';

                    data = {
                        company_id: company_id,
                        phones: clear_phone_numbers
                    };
                    response = await send_curl('https://link/b24_get_info', data);
                    phones_from_db = response.phones;
                    console.log(phones_from_db);
                    //console.log(response);
                    if (!phones_from_db.find(phone => phone.status_phone === 'in_progress')) {
                        if ((readyCount = phones_from_db.filter(phone => phone.status_phone === 'ready_to_call').length) > 0) {
                            set_autocall_button_state(autocall_all_button_1_el, 'all_start_pause', 'ready');
                            set_autocall_button_state(autocall_all_button_2_el, 'all_stop_block', 'unavailable');
                            autocall_all_status_el.setAttribute('data-autocall-all-state', 'ready');
                            autocall_all_status_el.innerHTML = `Готовы ${readyCount}/${totalCount} к обзвону`;
                        } else {
                            set_autocall_button_state(autocall_all_button_1_el, 'all_start_pause', 'blocked');
                            set_autocall_button_state(autocall_all_button_2_el, 'all_stop_block', 'unavailable');
                            autocall_all_status_el.setAttribute('data-autocall-all-state', 'blocked');
                            autocall_all_status_el.innerHTML = `Все номера заблокированы на 7 дней`;
                        }
                    } else {
                        in_progress_count = phones_from_db.filter(phone => phone.status_phone === 'in_progress' || phone.status_phone === 'in_queue').length;
                        autocall_all_status_el.innerHTML = `Идет обзвон ${in_progress_count}/${totalCount}`;
                    }
                    break;
                case 'in_queue':
                    data = {
                        user_id: user_id,
                        responsible_id: responsible_user_id,
                        company: company,
                        company_id: company_id,
                        phones: [
                            {
                                phone: phone_number,
                                action_id: 2
                            }
                        ]
                    };
                    response = await send_curl("https://link/b24", data);
                    status = response.phones[0].status;
                    //console.log(status);
                    phones_from_db.find(item => item.phone === phone_number).status_phone = status;
                    set_autocall_button_state(autocall_one_button_1_el, 'one_start_stop', status);
                    autocall_one_status_el.setAttribute('data-autocall-one-status', status);
                    // autocall_one_button_1_el.setAttribute('data-autocall-one-status', status);
                    autocall_one_status_el.innerHTML = 'Готов к обзвону';
                    console.log(phones_from_db);
                    break;
                case 'in_pause':
                    data = {
                        user_id: user_id,
                        responsible_id: responsible_user_id,
                        company: company,
                        company_id: company_id,
                        phones: [
                            {
                                phone: phone_number,
                                action_id: 1
                            }
                        ]
                    };
                    response = await send_curl("https://link/b24", data);
                    status = response.phones[0].status;
                    console.log(status);
                    phones_from_db.find(item => item.phone === phone_number).status_phone = status;
                    set_autocall_button_state(autocall_one_button_1_el, 'one_start_stop', status);
                    autocall_one_status_el.setAttribute('data-autocall-one-status', status);
                    // autocall_one_button_1_el.setAttribute('data-autocall-one-status', status);
                    autocall_one_status_el.innerHTML = 'Идёт обзвон';

                    in_progress_count = phones_from_db.filter(phone => phone.status_phone === 'in_progress' || phone.status_phone === 'in_queue').length;
                    set_autocall_button_state(autocall_all_button_1_el, 'all_start_pause', 'pause');
                    autocall_all_status_el.setAttribute('data-autocall-all-state', 'calling');
                    autocall_all_status_el.innerHTML = `Обзвон возобновлён ${in_progress_count}/${totalCount}`;
                    console.log(phones_from_db);
                    break;
            }
            data = {
                company_id: company_id,
                phones: clear_phone_numbers
            };
            response = await send_curl('https://link/b24_get_info', data);
            phones_from_db = response.phones;
            updated_one_status(phones_from_db, phone_block_el);
            console.log(phones_from_db);
        });
    });

    async function send_curl(url, data) {
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer TOKEN' 
                },
                body: JSON.stringify(data)
            });
            return response.json();
        } catch (error) {
            console.error('Ошибка запроса:', error);
        }
    };

    async function updated_one_status(phones, phone_element) {
        for (let element of phone_element.querySelectorAll('.crm-entity-widget-content-block-mutlifield')) {
            const phone_number = element.querySelector('.crm-entity-phone-number').getAttribute('title').replace(/\D/g, '').trim();
            status = phones.find(phone => phone.phone === phone_number).status_phone;
            let status_el = element.querySelector('.autocall_one_status');
            let button = element.querySelector('.autocall_one_action');
            switch (status) {
                case 'ready_to_call':
                    set_autocall_button_state(button, 'one_start_stop', status);
                    status_el.innerHTML = 'Готов к обзвону';
                    break;
                case 'in_queue':
                    set_autocall_button_state(button, 'one_start_stop', status);
                    status_el.innerHTML = 'В очереди обзвона';
                    break;
                case 'in_progress':
                    set_autocall_button_state(button, 'one_start_stop', status);
                    status_el.innerHTML = 'Идёт обзвон';
                    break;
                case 'block':
                    // console.log(status);
                    set_autocall_button_state(button, 'one_start_stop', status);
                    status_el.innerHTML = 'Номер заблокирован на 7 дней';
                    break;
                case 'in_pause':
                    set_autocall_button_state(button, 'one_start_stop', status);
                    status_el.innerHTML = 'На паузе';
                    break;
            };
        };
    };
}