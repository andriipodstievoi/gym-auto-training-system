import 'leaflet/dist/leaflet.min.css';

import { divIcon, latLngBounds, map, marker, tileLayer } from 'leaflet';

// Loaded only by the branch index, via importmap(['app', 'map']).

const RIGA_CENTRE = [56.9496, 24.1052];
const OSM_TILES = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const OSM_ATTRIBUTION =
    '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

/**
 * Leaflet's default marker is a PNG resolved relative to the stylesheet, which
 * AssetMapper renames. A div icon sidesteps that and matches the brand.
 */
const pin = (isOpen) =>
    divIcon({
        className: '',
        html: `<span class="branch-pin${isOpen ? ' branch-pin--open' : ''}"></span>`,
        iconSize: [20, 20],
        iconAnchor: [10, 10],
        popupAnchor: [0, -12],
    });

/**
 * Built as DOM rather than an HTML string: branch names come from the database
 * and must never be parsed as markup.
 */
function popupFor(branch, labels) {
    const wrapper = document.createElement('div');
    wrapper.className = 'branch-popup';

    const name = document.createElement('p');
    name.className = 'branch-popup__name';
    name.textContent = branch.name;
    wrapper.append(name);

    const address = document.createElement('p');
    address.textContent = branch.address;
    wrapper.append(address);

    const hours = document.createElement('p');
    hours.className = branch.open ? 'branch-popup__open' : 'branch-popup__shut';
    hours.textContent = branch.hours
        ? `${branch.open ? labels.openNow : labels.closedNow} · ${labels.today} ${branch.hours}`
        : labels.closedToday;
    wrapper.append(hours);

    const link = document.createElement('a');
    link.className = 'branch-popup__link';
    link.href = branch.url;
    link.textContent = labels.view;
    wrapper.append(link);

    return wrapper;
}

function render(element) {
    const branches = JSON.parse(element.dataset.branches || '[]');
    const labels = JSON.parse(element.dataset.labels || '{}');

    const instance = map(element, { scrollWheelZoom: false }).setView(RIGA_CENTRE, 12);

    tileLayer(OSM_TILES, { attribution: OSM_ATTRIBUTION, maxZoom: 19 }).addTo(instance);

    const placed = branches.map((branch) =>
        marker([branch.lat, branch.lng], { icon: pin(branch.open), title: branch.name })
            .addTo(instance)
            .bindPopup(popupFor(branch, labels)),
    );

    if (placed.length > 0) {
        instance.fitBounds(latLngBounds(placed.map((m) => m.getLatLng())), {
            padding: [56, 56],
            maxZoom: 14,
        });
    }

    // The map is only useful once it can be zoomed; a click enables the wheel
    // so the page still scrolls past it on the way down.
    instance.once('focus', () => instance.scrollWheelZoom.enable());
}

document.querySelectorAll('[data-controller="branch-map"]').forEach(render);
