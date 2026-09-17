const rows = Array.from({length: 33}, (_, i) => ({
  date: `1403/${String((i % 12) + 1).padStart(2, '0')}/${String((i % 28) + 1).padStart(2, '0')}`,
  weekday: 'شنبه',
  entryTime: '08:00',
  exitTime: '16:30',
  entryTime2: '',
  exitTime2: '',
  delay: '00:00',
  earlyStart: '00:00',
  earlyExit: '00:00',
  overtime: '00:00',
  calculatedTime: '08:30',
  isHoliday: false
}));
localStorage.setItem('hozoorReportData', JSON.stringify(rows));
localStorage.setItem('selectedUsername', 'testuser');
localStorage.setItem('totalOvertime', '00:30');
localStorage.setItem('totalPresenceTime', '08:00');
localStorage.setItem('totalDelayTime', '00:00');
localStorage.setItem('totalEarlyStart', '00:00');
localStorage.setItem('totalEarlyExit', '00:00');
window.location.reload();
