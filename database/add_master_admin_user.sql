/* Add the master-admin user 'ali' to user_table.
   Run this if the user does not already exist. */
IF NOT EXISTS (SELECT 1 FROM user_table WHERE LTRIM(RTRIM(username)) = 'ali')
BEGIN
    INSERT INTO user_table (
        id, username, password, name, last_name, department,
        work_hours, substitute, role, hozoor_num,
        shanbeh, yekshanbeh, doshanbeh, seshanbeh, chrshanbeh, panjshanbeh,
        is_active
    ) VALUES (
        960, 'ali', 'admin', 'علی', 'مدیر اصلی', 'مدیریت',
        '00:00 - 00:00', 'بدون جانشین', 'admin', NULL,
        NULL, NULL, NULL, NULL, NULL, NULL,
        'active'
    );
    PRINT 'Master admin user ali created successfully.';
END
ELSE
BEGIN
    PRINT 'User ali already exists.';
END;
