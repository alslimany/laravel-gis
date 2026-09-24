import { useEffect, useRef } from 'react';
import { hydrateBladeIcons } from '../bladeIcons';

/**
 * Moves server-rendered Blade content into the React shell main area.
 */
export default function BladeHost() {
    const hostRef = useRef(null);

    useEffect(() => {
        const slot = document.getElementById('console-blade-slot');
        const host = hostRef.current;
        if (!slot || !host) {
            return undefined;
        }

        while (slot.firstChild) {
            host.appendChild(slot.firstChild);
        }
        hydrateBladeIcons(host);

        return () => {
            while (host.firstChild) {
                slot.appendChild(host.firstChild);
            }
        };
    }, []);

    return <div ref={hostRef} className="console-blade-host" />;
}
