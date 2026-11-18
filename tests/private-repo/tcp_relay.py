# A TCP forwarder standing in for the NAT in https_names.sh: it listens on its own address and passes each connection on to the server, so the
# server sees it arrive on its own address while the client dialed the forwarder's (the same thing a port forward on a VM host does).
# usage: python3 tcp_relay.py LISTEN_PORT TARGET_HOST TARGET_PORT
import asyncio, sys

async def pipe(r, w):
    try:
        while True:
            data = await r.read(65536)
            if not data:
                break
            w.write(data)
            await w.drain()
    except Exception:
        pass
    finally:
        try:
            w.close()
        except Exception:
            pass

async def handle(cr, cw):
    try:
        sr, sw = await asyncio.open_connection(sys.argv[2], int(sys.argv[3]))
    except Exception:
        cw.close()
        return
    await asyncio.gather(pipe(cr, sw), pipe(sr, cw))

async def main():
    srv = await asyncio.start_server(handle, '0.0.0.0', int(sys.argv[1]))
    async with srv:
        await srv.serve_forever()

asyncio.run(main())
