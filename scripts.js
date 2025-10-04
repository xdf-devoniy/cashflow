document.addEventListener('DOMContentLoaded', function() {
    const cashCheckbox = document.getElementById('cash');
    const clickCheckbox = document.getElementById('click');
    const cashInCheckbox = document.getElementById('cash_in');
    const cashOutCheckbox = document.getElementById('cash_out');
    
    cashCheckbox.addEventListener('change', function() {
        if (this.checked) {
            clickCheckbox.checked = false;
        }
    });

    clickCheckbox.addEventListener('change', function() {
        if (this.checked) {
            cashCheckbox.checked = false;
        }
    });

    cashInCheckbox.addEventListener('change', function() {
        if (this.checked) {
            cashOutCheckbox.checked = false;
        }
    });

    cashOutCheckbox.addEventListener('change', function() {
        if (this.checked) {
            cashInCheckbox.checked = false;
        }
    });
});

const currentDateElement = document.getElementById('currentDate');
let currentDate = new Date(currentDateElement.innerText);

document.getElementById('prevDate').addEventListener('click', function() {
    currentDate.setDate(currentDate.getDate() - 1);
    updateDate();
});

document.getElementById('nextDate').addEventListener('click', function() {
    currentDate.setDate(currentDate.getDate() + 1);
    updateDate();
});

function updateDate() {
    const year = currentDate.getFullYear();
    const month = String(currentDate.getMonth() + 1).padStart(2, '0');
    const day = String(currentDate.getDate()).padStart(2, '0');
    const formattedDate = `${year}-${month}-${day}`;
    currentDateElement.innerText = formattedDate;

    // Make an AJAX request to fetch and display transactions for the updated date
    location.href = `index.php?date=${formattedDate}`;
}
