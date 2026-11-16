/*
 * Live progress from Laravel Reverb, without npm packages: a small client for the Pusher protocol
 * that Reverb speaks, for one private channel.
 *
 * KhmerLive.connect(config, onEvent, onState)
 *   config   {key, scheme, port, auth, channel, csrf} from the page (VideoJobController@index)
 *   onEvent  (name, data) for each event on the channel
 *   onState  (live) when the channel starts or stops getting events
 *
 * It reconnects by itself, waiting 2 s, then 4, 8 ... up to a minute. While it is not live, the
 * page asks for the status every 3 seconds as before, so a stopped Reverb only costs requests.
 */
window.KhmerLive = (() => {
    const parse = (text) => { try { return JSON.parse(text); } catch { return null; } };

    function connect(config, onEvent, onState) {
        let socket = null, pinger = null, wait = 2000, live = false;
        const setLive = (on) => {
            if (live === on) return;
            live = on;
            onState(on);
        };
        const send = (event, data) => socket?.readyState === WebSocket.OPEN && socket.send(JSON.stringify({ event, data }));

        /** Sign the private channel for this connection, as the site's /broadcasting/auth allows. */
        async function authorize(socketId) {
            const body = new URLSearchParams({ socket_id: socketId, channel_name: config.channel });
            const response = await fetch(config.auth, {
                method: 'POST', body, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': config.csrf },
            });
            if (!response.ok) throw new Error(`auth HTTP ${response.status}`);
            return (await response.json()).auth;
        }

        function open() {
            const host = location.hostname.includes(':') ? `[${location.hostname}]` : location.hostname;
            try {
                socket = new WebSocket(`${config.scheme}://${host}:${config.port}/app/${config.key}?protocol=7&client=khmer-dub&version=1.0`);
            } catch {
                return later();
            }
            socket.onmessage = async (message) => {
                const packet = parse(message.data);
                if (!packet) return;
                const data = typeof packet.data === 'string' ? parse(packet.data) : packet.data;
                switch (packet.event) {
                    case 'pusher:connection_established':
                        wait = 2000;
                        clearInterval(pinger); // the server drops a connection that says nothing for this long
                        pinger = setInterval(() => send('pusher:ping', {}), (data?.activity_timeout || 30) * 1000);
                        try {
                            send('pusher:subscribe', { channel: config.channel, auth: await authorize(data.socket_id) });
                        } catch {
                            socket.close();
                        }
                        return;
                    case 'pusher_internal:subscription_succeeded':
                        return setLive(true);
                    case 'pusher:ping':
                        return send('pusher:pong', {});
                    case 'pusher:error':
                        return socket.close();
                }
                if (packet.channel === config.channel && packet.event && !packet.event.startsWith('pusher')) onEvent(packet.event, data);
            };
            socket.onclose = () => {
                clearInterval(pinger);
                setLive(false);
                later();
            };
            socket.onerror = () => socket.close();
        }

        function later() {
            setTimeout(open, wait);
            wait = Math.min(wait * 2, 60000);
        }

        open();
    }

    return { connect };
})();
