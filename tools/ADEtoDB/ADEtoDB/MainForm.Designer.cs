#nullable disable
namespace ADEtoDB;

partial class MainForm
{
    private System.ComponentModel.IContainer components = null;
    private Label rootPathLabel;
    private TextBox rootPathTextBox;
    private Button browseButton;
    private CheckBox splitByProvinceCheckBox;
    private ProgressBar progressBar;
    private Label statusLabel;
    private TextBox logTextBox;
    private Button startButton;
    private Button cancelButton;
    private Button saveButton;

    protected override void Dispose(bool disposing)
    {
        if (disposing) {
            components?.Dispose();
        }

        base.Dispose(disposing);
    }

    #region Windows Form Designer generated code

    private void InitializeComponent()
    {
        rootPathLabel = new Label();
        rootPathTextBox = new TextBox();
        browseButton = new Button();
        splitByProvinceCheckBox = new CheckBox();
        progressBar = new ProgressBar();
        statusLabel = new Label();
        logTextBox = new TextBox();
        startButton = new Button();
        cancelButton = new Button();
        saveButton = new Button();
        SuspendLayout();
        // 
        // rootPathLabel
        // 
        rootPathLabel.AutoSize = true;
        rootPathLabel.Location = new Point(16, 18);
        rootPathLabel.Name = "rootPathLabel";
        rootPathLabel.Size = new Size(142, 15);
        rootPathLabel.TabIndex = 0;
        rootPathLabel.Text = "Cartella radice ADE (D:\\)";
        // 
        // rootPathTextBox
        // 
        rootPathTextBox.Anchor = AnchorStyles.Top | AnchorStyles.Left | AnchorStyles.Right;
        rootPathTextBox.Location = new Point(16, 40);
        rootPathTextBox.Name = "rootPathTextBox";
        rootPathTextBox.Size = new Size(698, 23);
        rootPathTextBox.TabIndex = 1;
        // 
        // browseButton
        // 
        browseButton.Anchor = AnchorStyles.Top | AnchorStyles.Right;
        browseButton.Location = new Point(720, 39);
        browseButton.Name = "browseButton";
        browseButton.Size = new Size(96, 25);
        browseButton.TabIndex = 2;
        browseButton.Text = "Sfoglia...";
        browseButton.UseVisualStyleBackColor = true;
        browseButton.Click += BrowseButton_Click;
        // 
        // splitByProvinceCheckBox
        // 
        splitByProvinceCheckBox.AutoSize = true;
        splitByProvinceCheckBox.Location = new Point(16, 78);
        splitByProvinceCheckBox.Name = "splitByProvinceCheckBox";
        splitByProvinceCheckBox.Size = new Size(229, 19);
        splitByProvinceCheckBox.TabIndex = 3;
        splitByProvinceCheckBox.Text = "Genera un file SQL separato per provincia";
        splitByProvinceCheckBox.UseVisualStyleBackColor = true;
        // 
        // progressBar
        // 
        progressBar.Anchor = AnchorStyles.Top | AnchorStyles.Left | AnchorStyles.Right;
        progressBar.Location = new Point(16, 111);
        progressBar.Name = "progressBar";
        progressBar.Size = new Size(800, 18);
        progressBar.TabIndex = 4;
        // 
        // statusLabel
        // 
        statusLabel.Anchor = AnchorStyles.Top | AnchorStyles.Left | AnchorStyles.Right;
        statusLabel.Location = new Point(16, 136);
        statusLabel.Name = "statusLabel";
        statusLabel.Size = new Size(800, 34);
        statusLabel.TabIndex = 5;
        statusLabel.Text = "Stato";
        // 
        // logTextBox
        // 
        logTextBox.Anchor = AnchorStyles.Top | AnchorStyles.Bottom | AnchorStyles.Left | AnchorStyles.Right;
        logTextBox.Location = new Point(16, 173);
        logTextBox.Multiline = true;
        logTextBox.Name = "logTextBox";
        logTextBox.ReadOnly = true;
        logTextBox.ScrollBars = ScrollBars.Vertical;
        logTextBox.Size = new Size(800, 290);
        logTextBox.TabIndex = 6;
        // 
        // startButton
        // 
        startButton.Anchor = AnchorStyles.Bottom | AnchorStyles.Right;
        startButton.Location = new Point(470, 478);
        startButton.Name = "startButton";
        startButton.Size = new Size(108, 32);
        startButton.TabIndex = 7;
        startButton.Text = "Avvia elaborazione";
        startButton.UseVisualStyleBackColor = true;
        startButton.Click += StartButton_Click;
        // 
        // cancelButton
        // 
        cancelButton.Anchor = AnchorStyles.Bottom | AnchorStyles.Right;
        cancelButton.Location = new Point(584, 478);
        cancelButton.Name = "cancelButton";
        cancelButton.Size = new Size(108, 32);
        cancelButton.TabIndex = 8;
        cancelButton.Text = "Annulla";
        cancelButton.UseVisualStyleBackColor = true;
        cancelButton.Click += CancelButton_Click;
        // 
        // saveButton
        // 
        saveButton.Anchor = AnchorStyles.Bottom | AnchorStyles.Right;
        saveButton.Location = new Point(698, 478);
        saveButton.Name = "saveButton";
        saveButton.Size = new Size(118, 32);
        saveButton.TabIndex = 9;
        saveButton.Text = "Salva file SQL";
        saveButton.UseVisualStyleBackColor = true;
        saveButton.Click += SaveButton_Click;
        // 
        // MainForm
        // 
        AutoScaleDimensions = new SizeF(7F, 15F);
        AutoScaleMode = AutoScaleMode.Font;
        ClientSize = new Size(834, 522);
        Controls.Add(saveButton);
        Controls.Add(cancelButton);
        Controls.Add(startButton);
        Controls.Add(logTextBox);
        Controls.Add(statusLabel);
        Controls.Add(progressBar);
        Controls.Add(splitByProvinceCheckBox);
        Controls.Add(browseButton);
        Controls.Add(rootPathTextBox);
        Controls.Add(rootPathLabel);
        MinimumSize = new Size(850, 560);
        Name = "MainForm";
        StartPosition = FormStartPosition.CenterScreen;
        Text = "ADEtoDB";
        ResumeLayout(false);
        PerformLayout();
    }

    #endregion
}
