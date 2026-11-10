import Swal from 'sweetalert2';

window.Swal = Swal.mixin({
    buttonsStyling: false,
    reverseButtons: true,
    customClass: {
        popup: 'swal-ml-popup',
        title: 'swal-ml-title',
        htmlContainer: 'swal-ml-text',
        actions: 'swal-ml-actions',
        confirmButton: 'swal-ml-btn swal-ml-btn-primary',
        denyButton: 'swal-ml-btn swal-ml-btn-secondary',
        cancelButton: 'swal-ml-btn swal-ml-btn-secondary',
    },
});

import './borrador-cuenta';

import 'flatpickr/dist/flatpickr.css';

import './../../vendor/power-components/livewire-powergrid/dist/powergrid';
