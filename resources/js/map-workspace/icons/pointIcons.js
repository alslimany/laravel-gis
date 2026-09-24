import {
    IconAntenna,
    IconBolt,
    IconBuilding,
    IconBuildingBroadcastTower,
    IconBuildingFactory,
    IconBuildingHospital,
    IconCar,
    IconDroplet,
    IconFence,
    IconFlag,
    IconHelicopter,
    IconHome,
    IconMapPin,
    IconSatellite,
    IconSchool,
    IconTree,
    IconWifi,
} from '@tabler/icons-react';

/** Point symbols offered in the style panel. Circle stays the default. */
export const POINT_ICONS = [
    { id: 'circle', label: 'Circle' },
    { id: 'building-broadcast-tower', label: 'Tower', Icon: IconBuildingBroadcastTower },
    { id: 'antenna', label: 'Antenna', Icon: IconAntenna },
    { id: 'satellite', label: 'Satellite', Icon: IconSatellite },
    { id: 'wifi', label: 'Signal', Icon: IconWifi },
    { id: 'building', label: 'Building', Icon: IconBuilding },
    { id: 'building-factory', label: 'Factory', Icon: IconBuildingFactory },
    { id: 'building-hospital', label: 'Hospital', Icon: IconBuildingHospital },
    { id: 'school', label: 'School', Icon: IconSchool },
    { id: 'home', label: 'Home', Icon: IconHome },
    { id: 'fence', label: 'Fence', Icon: IconFence },
    { id: 'car', label: 'Vehicle', Icon: IconCar },
    { id: 'helicopter', label: 'Aircraft', Icon: IconHelicopter },
    { id: 'tree', label: 'Tree', Icon: IconTree },
    { id: 'droplet', label: 'Water', Icon: IconDroplet },
    { id: 'bolt', label: 'Power', Icon: IconBolt },
    { id: 'flag', label: 'Flag', Icon: IconFlag },
    { id: 'map-pin', label: 'Pin', Icon: IconMapPin },
];

export function mapIconUrl(name, color) {
    const safe = /^#[0-9A-Fa-f]{6}$/.test(color || '') ? color : '#3388ff';
    return `/map-icons/${name}.svg?color=${encodeURIComponent(safe)}`;
}
