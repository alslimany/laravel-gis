import type { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M10 10 V30 H30" stroke="currentColor" strokeWidth="2.5" strokeLinecap="square" />
            <circle cx="10" cy="10" r="2.5" fill="currentColor" />
            <circle cx="30" cy="30" r="2.5" fill="currentColor" />
            <line x1="10" y1="30" x2="28" y2="12" stroke="currentColor" strokeWidth="1.5" strokeDasharray="2 2" />
        </svg>
    );
}
